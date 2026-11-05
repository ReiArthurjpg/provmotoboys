<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class MotoboyRepository
{
    private const WEEKDAY_MAP = [
        'segunda-feira' => ['num' => 1, 'label' => 'Segunda-feira'],
        'segunda'       => ['num' => 1, 'label' => 'Segunda-feira'],
        'seg'           => ['num' => 1, 'label' => 'Segunda-feira'],
        'terça-feira'   => ['num' => 2, 'label' => 'Terça-feira'],
        'terca-feira'   => ['num' => 2, 'label' => 'Terça-feira'],
        'terça'         => ['num' => 2, 'label' => 'Terça-feira'],
        'terca'         => ['num' => 2, 'label' => 'Terça-feira'],
        'ter'           => ['num' => 2, 'label' => 'Terça-feira'],
        'quarta-feira'  => ['num' => 3, 'label' => 'Quarta-feira'],
        'quarta'        => ['num' => 3, 'label' => 'Quarta-feira'],
        'qua'           => ['num' => 3, 'label' => 'Quarta-feira'],
        'quinta-feira'  => ['num' => 4, 'label' => 'Quinta-feira'],
        'quinta'        => ['num' => 4, 'label' => 'Quinta-feira'],
        'qui'           => ['num' => 4, 'label' => 'Quinta-feira'],
        'sexta-feira'   => ['num' => 5, 'label' => 'Sexta-feira'],
        'sexta'         => ['num' => 5, 'label' => 'Sexta-feira'],
        'sex'           => ['num' => 5, 'label' => 'Sexta-feira'],
        'sábado'        => ['num' => 6, 'label' => 'Sábado'],
        'sabado'        => ['num' => 6, 'label' => 'Sábado'],
        'sab'           => ['num' => 6, 'label' => 'Sábado'],
        'domingo'       => ['num' => 7, 'label' => 'Domingo'],
        'dom'           => ['num' => 7, 'label' => 'Domingo'],
    ];

    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * @return array{
     *   totalCount: int,
     *   aprovados: int,
     *   pendentes: int,
     *   rascunhos: int,
     *   reprovados: int,
     *   comMei: int,
     *   semMei: int,
     *   approvalRate: int
     * }
     */
    public function getStats(): array
    {
        $sql = "SELECT
                    COUNT(*) AS total_count,
                    SUM(CASE WHEN status = 'aprovado' THEN 1 ELSE 0 END) AS aprovados,
                    SUM(CASE WHEN status = 'pendente_analise' THEN 1 ELSE 0 END) AS pendentes,
                    SUM(CASE WHEN status = 'rascunho' THEN 1 ELSE 0 END) AS rascunhos,
                    SUM(CASE WHEN status = 'reprovado' THEN 1 ELSE 0 END) AS reprovados,
                    SUM(CASE WHEN mei_cnpj IS NOT NULL AND mei_cnpj <> '' THEN 1 ELSE 0 END) AS com_mei
                FROM motoboys";

        $stmt = $this->pdo->query($sql);
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : [];

        $totalCount = (int) ($row['total_count'] ?? 0);
        $aprovados = (int) ($row['aprovados'] ?? 0);
        $pendentes = (int) ($row['pendentes'] ?? 0);
        $rascunhos = (int) ($row['rascunhos'] ?? 0);
        $reprovados = (int) ($row['reprovados'] ?? 0);
        $comMei = (int) ($row['com_mei'] ?? 0);
        $semMei = max(0, $totalCount - $comMei);
        $approvalRate = $totalCount > 0 ? (int) round(($aprovados / $totalCount) * 100) : 0;

        return [
            'totalCount' => $totalCount,
            'aprovados' => $aprovados,
            'pendentes' => $pendentes,
            'rascunhos' => $rascunhos,
            'reprovados' => $reprovados,
            'comMei' => $comMei,
            'semMei' => $semMei,
            'approvalRate' => $approvalRate,
        ];
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function paginateForPanel(
        ?string $status,
        ?string $meiFilter,
        ?string $search,
        string $sort,
        int $page,
        int $perPage
    ): array {
        $conditions = [];
        $params = [];

        if ($status !== null && $status !== '') {
            $conditions[] = 'status = :status';
            $params['status'] = $status;
        }

        if ($meiFilter === 'with_mei') {
            $conditions[] = "(mei_cnpj IS NOT NULL AND mei_cnpj <> '')";
        } elseif ($meiFilter === 'without_mei') {
            $conditions[] = "(mei_cnpj IS NULL OR mei_cnpj = '')";
        }

        if ($search !== null && trim($search) !== '') {
            $q = '%' . trim($search) . '%';
            $cleanDigits = preg_replace('/\D+/', '', $search) ?? '';
            $qDigits = $cleanDigits !== '' ? '%' . $cleanDigits . '%' : $q;

            $conditions[] = '(
                name LIKE :q_name
                OR email LIKE :q_email
                OR plate LIKE :q_plate
                OR protocol LIKE :q_protocol
                OR cpf LIKE :q_cpf
                OR phone LIKE :q_phone
            )';
            $params['q_name'] = $q;
            $params['q_email'] = $q;
            $params['q_plate'] = $q;
            $params['q_protocol'] = $q;
            $params['q_cpf'] = $qDigits;
            $params['q_phone'] = $qDigits;
        }

        $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM motoboys {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $orderBy = match ($sort) {
            'name' => 'name ASC, id DESC',
            'step' => 'current_step DESC, id DESC',
            default => 'created_at DESC, id DESC',
        };

        $offset = max(0, ($page - 1) * $perPage);
        $sql = "SELECT
                    public_id AS id,
                    protocol,
                    name,
                    cpf,
                    email,
                    phone,
                    plate,
                    mei_cnpj,
                    status,
                    current_step,
                    created_at,
                    updated_at
                FROM motoboys
                {$where}
                ORDER BY {$orderBy}
                LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['current_step'] = (int) ($r['current_step'] ?? 1);
        }

        return [
            'items' => $rows,
            'total' => $total,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByPublicId(string $publicId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM motoboys WHERE public_id = :public_id LIMIT 1');
        $stmt->execute(['public_id' => $publicId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBankAccount(int $motoboyId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT bank_code, bank_name, agency, account, account_type, holder_cpf, pix_type, pix_key
             FROM motoboy_bank_accounts
             WHERE motoboy_id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $motoboyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @return array<int, string>
     */
    public function findAvailabilityDays(int $motoboyId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT day_label FROM motoboy_availability WHERE motoboy_id = :id ORDER BY weekday ASC'
        );
        $stmt->execute(['id' => $motoboyId]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findTermsAcceptance(int $motoboyId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT terms_version, accepted_at, ip_address FROM terms_acceptances WHERE motoboy_id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $motoboyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function existsByCpfOrEmail(string $cpf, string $email, ?int $ignoreId = null): ?string
    {
        $sqlCpf = "SELECT 1 FROM motoboys WHERE cpf = :cpf AND status <> 'rascunho'";
        $paramsCpf = ['cpf' => $cpf];
        if ($ignoreId !== null) {
            $sqlCpf .= ' AND id <> :id';
            $paramsCpf['id'] = $ignoreId;
        }
        $stmtCpf = $this->pdo->prepare($sqlCpf . ' LIMIT 1');
        $stmtCpf->execute($paramsCpf);
        if ($stmtCpf->fetchColumn()) {
            return 'cpf';
        }

        $sqlEmail = "SELECT 1 FROM motoboys WHERE email = :email AND status <> 'rascunho'";
        $paramsEmail = ['email' => $email];
        if ($ignoreId !== null) {
            $sqlEmail .= ' AND id <> :id';
            $paramsEmail['id'] = $ignoreId;
        }
        $stmtEmail = $this->pdo->prepare($sqlEmail . ' LIMIT 1');
        $stmtEmail->execute($paramsEmail);
        if ($stmtEmail->fetchColumn()) {
            return 'email';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createFullMotoboy(array $payload): int
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO motoboys (
                    public_id, token_hash, current_step, status, protocol,
                    name, cpf, birth_date, email, phone, password_hash,
                    plate, mei_cnpj, cnh, cpf_final, email_final, ip_origem, expires_at
                ) VALUES (
                    :public_id, :token_hash, 4, :status, :protocol,
                    :name, :cpf, :birth_date, :email, :phone, :password_hash,
                    :plate, :mei_cnpj, :cnh, :cpf_final, :email_final, :ip_origem, NULL
                )'
            );

            $stmt->execute([
                'public_id' => $payload['public_id'],
                'token_hash' => $payload['token_hash'],
                'status' => $payload['status'],
                'protocol' => $payload['protocol'],
                'name' => $payload['name'],
                'cpf' => $payload['cpf'],
                'birth_date' => $payload['birth_date'],
                'email' => $payload['email'],
                'phone' => $payload['phone'],
                'password_hash' => $payload['password_hash'],
                'plate' => $payload['plate'],
                'mei_cnpj' => $payload['mei_cnpj'],
                'cnh' => $payload['cnh'],
                'cpf_final' => $payload['cpf'],
                'email_final' => $payload['email'],
                'ip_origem' => $payload['ip_origem'],
            ]);

            $motoboyId = (int) $this->pdo->lastInsertId();

            if (!empty($payload['banking']) && is_array($payload['banking'])) {
                $this->upsertBankAccount($motoboyId, $payload['banking']);
            }

            if (!empty($payload['schedule']) && is_array($payload['schedule'])) {
                $this->replaceAvailabilityByLabels($motoboyId, $payload['schedule']);
            }

            $insTerms = $this->pdo->prepare(
                'INSERT INTO terms_acceptances (motoboy_id, terms_version, accepted_at, ip_address, user_agent)
                 VALUES (:motoboy_id, :terms_version, NOW(), :ip_address, :user_agent)
                 ON DUPLICATE KEY UPDATE
                   terms_version = VALUES(terms_version),
                   accepted_at = NOW(),
                   ip_address = VALUES(ip_address)'
            );
            $insTerms->execute([
                'motoboy_id' => $motoboyId,
                'terms_version' => $payload['terms_version'] ?? '1.1',
                'ip_address' => $payload['ip_origem'] ?? '127.0.0.1',
                'user_agent' => $payload['user_agent'] ?? 'LogMovy Admin Panel',
            ]);

            $this->pdo->commit();
            return $motoboyId;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<string, string> $bank
     */
    public function upsertBankAccount(int $motoboyId, array $bank): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO motoboy_bank_accounts (
                motoboy_id, bank_code, bank_name, agency, account,
                account_type, holder_cpf, pix_type, pix_key
            ) VALUES (
                :motoboy_id, :bank_code, :bank_name, :agency, :account,
                :account_type, :holder_cpf, :pix_type, :pix_key
            )
            ON DUPLICATE KEY UPDATE
                bank_code = VALUES(bank_code),
                bank_name = VALUES(bank_name),
                agency = VALUES(agency),
                account = VALUES(account),
                account_type = VALUES(account_type),
                holder_cpf = VALUES(holder_cpf),
                pix_type = VALUES(pix_type),
                pix_key = VALUES(pix_key)'
        );

        $stmt->execute([
            'motoboy_id' => $motoboyId,
            'bank_code' => $bank['bank_code'],
            'bank_name' => $bank['bank_name'],
            'agency' => $bank['agency'],
            'account' => $bank['account'],
            'account_type' => $bank['account_type'],
            'holder_cpf' => $bank['holder_cpf'],
            'pix_type' => $bank['pix_type'],
            'pix_key' => $bank['pix_key'],
        ]);
    }

    /**
     * @param array<int, string> $dayLabels
     */
    public function replaceAvailabilityByLabels(int $motoboyId, array $dayLabels): void
    {
        $del = $this->pdo->prepare('DELETE FROM motoboy_availability WHERE motoboy_id = :id');
        $del->execute(['id' => $motoboyId]);

        $ins = $this->pdo->prepare(
            'INSERT IGNORE INTO motoboy_availability (motoboy_id, weekday, day_label)
             VALUES (:motoboy_id, :weekday, :day_label)'
        );

        foreach ($dayLabels as $rawLabel) {
            $normalized = mb_strtolower(trim((string) $rawLabel), 'UTF-8');
            $mapped = self::WEEKDAY_MAP[$normalized] ?? null;
            if ($mapped === null) {
                continue;
            }
            $ins->execute([
                'motoboy_id' => $motoboyId,
                'weekday' => $mapped['num'],
                'day_label' => $mapped['label'],
            ]);
        }
    }

    public function updateStatusByPublicId(
        string $publicId,
        string $status,
        ?string $newPasswordHash = null
    ): bool {
        $current = $this->findByPublicId($publicId);
        if ($current === null) {
            return false;
        }

        $protocol = $current['protocol'];
        if (($protocol === null || $protocol === '') && $status !== 'rascunho') {
            $protocol = sprintf('LMV-%s%04d', date('Y'), random_int(1000, 9999));
        }

        $step = $status === 'aprovado' ? 4 : (int) ($current['current_step'] ?? 4);

        if ($newPasswordHash !== null && $newPasswordHash !== '') {
            $stmt = $this->pdo->prepare(
                'UPDATE motoboys
                 SET status = :status,
                     protocol = :protocol,
                     current_step = :current_step,
                     password_hash = :password_hash
                 WHERE public_id = :public_id'
            );
            $stmt->execute([
                'status' => $status,
                'protocol' => $protocol,
                'current_step' => $step,
                'password_hash' => $newPasswordHash,
                'public_id' => $publicId,
            ]);
        } else {
            $stmt = $this->pdo->prepare(
                'UPDATE motoboys
                 SET status = :status,
                     protocol = :protocol,
                     current_step = :current_step
                 WHERE public_id = :public_id'
            );
            $stmt->execute([
                'status' => $status,
                'protocol' => $protocol,
                'current_step' => $step,
                'public_id' => $publicId,
            ]);
        }

        return $stmt->rowCount() >= 0;
    }

    public function deleteByPublicId(string $publicId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM motoboys WHERE public_id = :public_id');
        $stmt->execute(['public_id' => $publicId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAllBanks(): array
    {
        $stmt = $this->pdo->query(
            'SELECT code, value, label, short_desc AS `short`, badge, color FROM banks ORDER BY sort_order ASC'
        );
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }
}
