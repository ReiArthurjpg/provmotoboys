<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\ApiException;
use App\Repository\MotoboyRepository;
use App\Service\WelcomeMailerService;
use App\Support\JsonResponse;
use App\Validation\DocumentValidator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class MotoboyController
{
    private const ALLOWED_STATUSES = ['rascunho', 'pendente_analise', 'aprovado', 'reprovado'];

    public function __construct(
        private readonly MotoboyRepository $repository,
        private readonly WelcomeMailerService $welcomeMailer,
        private readonly string $termsVersion = '1.1'
    ) {
    }

    /**
     * GET /api/v1/motoboys/stats e /api/v1/admin/motoboys/stats
     */
    public function stats(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return JsonResponse::send($response, $this->repository->getStats());
    }

    /**
     * GET /api/v1/motoboys e /api/v1/admin/motoboys
     */
    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        $status = isset($query['status']) ? trim((string) $query['status']) : null;
        if ($status !== null && $status !== '' && !in_array($status, self::ALLOWED_STATUSES, true)) {
            $status = null;
        }

        $mei = isset($query['mei']) ? trim((string) $query['mei']) : null;
        $search = isset($query['search'])
            ? trim((string) $query['search'])
            : (isset($query['q']) ? trim((string) $query['q']) : null);
        $sort = isset($query['sort']) ? trim((string) $query['sort']) : 'recent';

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(200, max(1, (int) ($query['perPage'] ?? 100)));

        $result = $this->repository->paginateForPanel($status, $mei, $search, $sort, $page, $perPage);
        $stats = $this->repository->getStats();

        return JsonResponse::send($response, [
            'page' => $page,
            'perPage' => $perPage,
            'total' => $result['total'],
            'stats' => $stats,
            'items' => $result['items'],
        ]);
    }

    /**
     * GET /api/v1/motoboys/{id} e /api/v1/admin/motoboys/{id}
     */
    public function detail(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $publicId = (string) ($args['id'] ?? '');
        $motoboy = $this->repository->findByPublicId($publicId);
        if ($motoboy === null) {
            throw ApiException::notFound('Motoboy não encontrado.');
        }

        return JsonResponse::send($response, $this->buildDetailPayload($motoboy));
    }

    /**
     * POST /api/v1/motoboys e /api/v1/admin/motoboys
     */
    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $raw = (string) $request->getBody();
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : (json_decode($raw, true) ?: []);

        $name = trim((string) ($body['name'] ?? $body['nome'] ?? ''));
        $cpf = DocumentValidator::digitsOnly((string) ($body['cpf'] ?? ''));
        $birthDate = trim((string) ($body['birthDate'] ?? $body['dataNascimento'] ?? '1995-01-01'));
        if ($birthDate === '') {
            $birthDate = '1995-01-01';
        }
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $phone = DocumentValidator::digitsOnly((string) ($body['phone'] ?? $body['celular'] ?? ''));
        $password = (string) ($body['password'] ?? $body['senha'] ?? 'LogMovy@2026');
        $plate = DocumentValidator::normalizePlate((string) ($body['plate'] ?? $body['placaMoto'] ?? ''));
        $meiRaw = DocumentValidator::digitsOnly((string) ($body['mei_cnpj'] ?? $body['cnpjMei'] ?? ''));
        $mei = strlen($meiRaw) === 14 ? $meiRaw : null;
        $cnhRaw = DocumentValidator::digitsOnly((string) ($body['cnh'] ?? ''));
        $cnh = $cnhRaw !== '' ? substr($cnhRaw, 0, 15) : null;

        $errors = [];
        if (mb_strlen($name) < 3) {
            $errors['name'] = 'Informe o nome completo do entregador.';
        }
        if (strlen($cpf) !== 11) {
            $errors['cpf'] = 'Informe um CPF com 11 dígitos.';
        }
        if ($plate === '') {
            $errors['plate'] = 'Informe a placa da motocicleta.';
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        if ($email === '') {
            $email = 'entregador.' . substr($cpf, -4) . '.' . time() . '@logmovy.com.br';
        }
        if ($phone === '') {
            $phone = '83999990000';
        }

        $duplicateField = $this->repository->existsByCpfOrEmail($cpf, $email);
        if ($duplicateField === 'cpf') {
            throw ApiException::conflict('Já existe um entregador cadastrado com este CPF.', 'DUPLICATE_CPF');
        }
        if ($duplicateField === 'email') {
            throw ApiException::conflict('Já existe um entregador cadastrado com este e-mail.', 'DUPLICATE_EMAIL');
        }

        $publicId = self::generateUuidV4();
        $protocol = sprintf('LMV-%s%04d', date('Y'), random_int(1000, 9999));
        $status = in_array((string) ($body['status'] ?? ''), self::ALLOWED_STATUSES, true)
            ? (string) $body['status']
            : 'pendente_analise';

        $bankingInput = is_array($body['banking'] ?? null) ? $body['banking'] : $body;
        $rawBank = trim((string) ($bankingInput['bank_name'] ?? $bankingInput['banco'] ?? '260 - Nu Pagamentos (Nubank)'));
        $bankCode = trim((string) ($bankingInput['bank_code'] ?? ''));
        $bankName = $rawBank;
        if ($bankCode === '' && str_contains($rawBank, ' - ')) {
            [$bankCode, $bankName] = array_map('trim', explode(' - ', $rawBank, 2));
        }
        if ($bankCode === '') {
            $bankCode = '260';
        }

        $rawAccType = strtolower(trim((string) ($bankingInput['account_type'] ?? $bankingInput['tipoConta'] ?? 'corrente')));
        $accountType = match (true) {
            str_contains($rawAccType, 'poup') => 'Conta poupança',
            str_contains($rawAccType, 'pag') => 'Conta pagamento',
            default => 'Conta corrente',
        };

        $rawPixType = trim((string) ($bankingInput['pix_type'] ?? $bankingInput['tipoChavePix'] ?? 'CPF'));
        $pixType = match (strtolower($rawPixType)) {
            'celular', 'telefone', 'phone' => 'Celular',
            'e-mail', 'email' => 'E-mail',
            'cnpj' => 'CNPJ',
            'chave aleatória', 'chave aleatoria', 'aleatoria', 'evp' => 'Chave aleatória',
            default => 'CPF',
        };

        $holderCpf = DocumentValidator::digitsOnly((string) ($bankingInput['holder_cpf'] ?? $bankingInput['cpfTitular'] ?? $cpf));
        if (strlen($holderCpf) !== 11) {
            $holderCpf = $cpf;
        }

        $banking = [
            'bank_code' => substr($bankCode, 0, 10),
            'bank_name' => substr($bankName, 0, 120),
            'agency' => substr(trim((string) ($bankingInput['agency'] ?? $bankingInput['agencia'] ?? '0001')) ?: '0001', 0, 20),
            'account' => substr(trim((string) ($bankingInput['account'] ?? $bankingInput['conta'] ?? '00000-0')) ?: '00000-0', 0, 30),
            'account_type' => $accountType,
            'holder_cpf' => $holderCpf,
            'pix_type' => $pixType,
            'pix_key' => substr(trim((string) ($bankingInput['pix_key'] ?? $bankingInput['chavePix'] ?? $cpf)) ?: $cpf, 0, 180),
        ];

        $schedule = $body['schedule'] ?? $body['diasDisponiveis'] ?? [
            'Segunda-feira',
            'Terça-feira',
            'Quarta-feira',
            'Quinta-feira',
            'Sexta-feira',
        ];
        if (!is_array($schedule)) {
            $schedule = ['Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira'];
        }

        $serverParams = $request->getServerParams();
        $ipOrigem = (string) ($serverParams['REMOTE_ADDR'] ?? '127.0.0.1');

        $this->repository->createFullMotoboy([
            'public_id' => $publicId,
            'token_hash' => hash('sha256', bin2hex(random_bytes(16))),
            'status' => $status,
            'protocol' => $protocol,
            'name' => $name,
            'cpf' => $cpf,
            'birth_date' => $birthDate,
            'email' => $email,
            'phone' => $phone,
            'password_hash' => password_hash($password !== '' ? $password : 'LogMovy@2026', PASSWORD_BCRYPT),
            'plate' => $plate,
            'mei_cnpj' => $mei,
            'cnh' => $cnh,
            'ip_origem' => $ipOrigem,
            'banking' => $banking,
            'schedule' => $schedule,
            'terms_version' => $this->termsVersion,
        ]);

        $created = $this->repository->findByPublicId($publicId);
        if ($created === null) {
            throw new ApiException('Erro ao recuperar o motoboy recém-cadastrado.', 500, 'CREATE_FAILED');
        }

        $item = [
            'id' => $created['public_id'],
            'protocol' => $created['protocol'],
            'name' => $created['name'],
            'cpf' => $created['cpf'],
            'email' => $created['email'],
            'phone' => $created['phone'],
            'plate' => $created['plate'],
            'mei_cnpj' => $created['mei_cnpj'],
            'status' => $created['status'],
            'current_step' => (int) $created['current_step'],
            'created_at' => $created['created_at'],
            'updated_at' => $created['updated_at'],
        ];

        return JsonResponse::send($response, [
            'message' => 'Entregador cadastrado com sucesso.',
            'item' => $item,
            'detail' => $this->buildDetailPayload($created),
        ], 201);
    }

    /**
     * PATCH /api/v1/motoboys/{id}/status e /api/v1/admin/motoboys/{id}/status
     * Quando o status passa a ser 'aprovado', gera a senha oficial de acesso, sincroniza no banco
     * e dispara o template de boas-vindas com Nome, E-mail, Senha e URL de Login.
     */
    public function updateStatus(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $publicId = (string) ($args['id'] ?? '');
        $raw = (string) $request->getBody();
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : (json_decode($raw, true) ?: []);
        $status = trim((string) ($body['status'] ?? ''));

        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            throw ApiException::validation([
                'status' => 'Status permitido: rascunho, pendente_analise, aprovado ou reprovado.',
            ]);
        }

        $motoboy = $this->repository->findByPublicId($publicId);
        if ($motoboy === null) {
            throw ApiException::notFound('Motoboy não encontrado.');
        }

        $welcomeData = null;
        $newPasswordHash = null;
        $accessPassword = null;

        if ($status === 'aprovado') {
            $customPassword = trim((string) ($body['password'] ?? ''));
            $cpfDigits = DocumentValidator::digitsOnly((string) ($motoboy['cpf'] ?? ''));
            $cpfSuffix = strlen($cpfDigits) >= 4 ? substr($cpfDigits, -4) : '2026';
            $plateClean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($motoboy['plate'] ?? 'LMV')) ?? 'LMV');
            $platePrefix = substr($plateClean, 0, 3) ?: 'LMV';

            $accessPassword = $customPassword !== ''
                ? $customPassword
                : sprintf('Log@%s%s', $cpfSuffix, $platePrefix);

            $newPasswordHash = password_hash($accessPassword, PASSWORD_BCRYPT);
        }

        $this->repository->updateStatusByPublicId($publicId, $status, $newPasswordHash);

        if ($status === 'aprovado' && $accessPassword !== null) {
            $updated = $this->repository->findByPublicId($publicId) ?? $motoboy;
            $welcomeData = $this->welcomeMailer->sendWelcomeCredentials([
                'name' => (string) $updated['name'],
                'email' => (string) $updated['email'],
                'phone' => (string) $updated['phone'],
                'protocol' => (string) ($updated['protocol'] ?? 'LMV-2026'),
                'plate' => (string) ($updated['plate'] ?? ''),
                'password' => $accessPassword,
                'loginUrl' => isset($body['loginUrl']) ? (string) $body['loginUrl'] : '',
            ]);
        }

        $payload = [
            'id' => $publicId,
            'status' => $status,
            'message' => $status === 'aprovado'
                ? 'Motoboy aprovado! Credenciais e template de boas-vindas gerados.'
                : 'Status do cadastro atualizado com sucesso.',
        ];

        if ($welcomeData !== null) {
            $payload['welcome'] = $welcomeData;
        }

        return JsonResponse::send($response, $payload);
    }

    /**
     * POST /api/v1/motoboys/{id}/send-welcome e /api/v1/admin/motoboys/{id}/send-welcome
     * Permite reenviar o template de boas-vindas com Nome, E-mail, Senha e URL de Login a qualquer momento.
     */
    public function sendWelcome(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $publicId = (string) ($args['id'] ?? '');
        $motoboy = $this->repository->findByPublicId($publicId);
        if ($motoboy === null) {
            throw ApiException::notFound('Motoboy não encontrado.');
        }

        $raw = (string) $request->getBody();
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : (json_decode($raw, true) ?: []);

        $customPassword = trim((string) ($body['password'] ?? ''));
        $cpfDigits = DocumentValidator::digitsOnly((string) ($motoboy['cpf'] ?? ''));
        $cpfSuffix = strlen($cpfDigits) >= 4 ? substr($cpfDigits, -4) : '2026';
        $plateClean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($motoboy['plate'] ?? 'LMV')) ?? 'LMV');
        $platePrefix = substr($plateClean, 0, 3) ?: 'LMV';

        $accessPassword = $customPassword !== ''
            ? $customPassword
            : sprintf('Log@%s%s', $cpfSuffix, $platePrefix);

        $newPasswordHash = password_hash($accessPassword, PASSWORD_BCRYPT);
        $this->repository->updateStatusByPublicId($publicId, 'aprovado', $newPasswordHash);

        $updated = $this->repository->findByPublicId($publicId) ?? $motoboy;
        $welcomeData = $this->welcomeMailer->sendWelcomeCredentials([
            'name' => (string) $updated['name'],
            'email' => (string) $updated['email'],
            'phone' => (string) $updated['phone'],
            'protocol' => (string) ($updated['protocol'] ?? 'LMV-2026'),
            'plate' => (string) ($updated['plate'] ?? ''),
            'password' => $accessPassword,
            'loginUrl' => isset($body['loginUrl']) ? (string) $body['loginUrl'] : '',
        ]);

        return JsonResponse::send($response, [
            'id' => $publicId,
            'status' => 'aprovado',
            'message' => 'Template de boas-vindas gerado e enviado com sucesso!',
            'welcome' => $welcomeData,
        ]);
    }

    /**
     * DELETE /api/v1/motoboys/{id} e /api/v1/admin/motoboys/{id}
     */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $publicId = (string) ($args['id'] ?? '');
        $deleted = $this->repository->deleteByPublicId($publicId);
        if (!$deleted) {
            throw ApiException::notFound('Motoboy não encontrado.');
        }

        return JsonResponse::send($response, [
            'id' => $publicId,
            'deleted' => true,
            'message' => 'Cadastro removido com sucesso.',
        ]);
    }

    /**
     * @param array<string, mixed> $motoboy
     * @return array<string, mixed>
     */
    private function buildDetailPayload(array $motoboy): array
    {
        $id = (int) $motoboy['id'];
        $bank = $this->repository->findBankAccount($id);
        $days = $this->repository->findAvailabilityDays($id);
        $terms = $this->repository->findTermsAcceptance($id);

        return [
            'id' => $motoboy['public_id'],
            'protocol' => $motoboy['protocol'],
            'status' => $motoboy['status'],
            'currentStep' => (int) $motoboy['current_step'],
            'profile' => [
                'name' => $motoboy['name'],
                'cpf' => DocumentValidator::formatCpf((string) $motoboy['cpf']),
                'birthDate' => $motoboy['birth_date'],
                'email' => $motoboy['email'],
                'phone' => DocumentValidator::formatPhone((string) $motoboy['phone']),
                'plate' => $motoboy['plate'],
                'mei' => DocumentValidator::formatCnpj($motoboy['mei_cnpj'] ?? null),
                'cnh' => $motoboy['cnh'],
                'ipOrigem' => $motoboy['ip_origem'],
            ],
            'banking' => $bank,
            'schedule' => $days,
            'terms' => $terms,
            'createdAt' => $motoboy['created_at'],
            'updatedAt' => $motoboy['updated_at'],
        ];
    }

    private static function generateUuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
