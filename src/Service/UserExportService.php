<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\OrganizationTypeEnum;
use App\Enum\UserRolesEnum;
use App\Enum\UserStatusEnum;
use App\Repository\Interface\UserRepositoryInterface;
use DateTimeImmutable;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Throwable;

final readonly class UserExportService
{
    public const HEADERS = [
        'ID do Usuário',
        'Nome do Usuário',
        'Nome Social',
        'E-mail',
        'Status',
        'Perfis de Acesso',
        'Usuário Criado em',
        'Usuário Atualizado em',
        'ID do Agente',
        'Nome do Agente',
        'CPF',
        'Cargo',
        'ID da Entidade',
        'Nome da Entidade',
        'Tipo da Entidade',
        'CNPJ',
        'Município',
        'UF',
        'Responsável pela Entidade',
    ];

    private const CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function __construct(
        private UserRepositoryInterface $userRepository,
        private ClockInterface $clock,
        #[Autowire('%kernel.project_dir%/storage/regmel')]
        private string $exportDirectory,
    ) {
    }

    public function export(): BinaryFileResponse
    {
        $this->createExportDirectory();

        $filePath = tempnam($this->exportDirectory, 'usuarios_e_vinculos_');
        if (false === $filePath) {
            throw new RuntimeException('Não foi possível criar o arquivo temporário da exportação.');
        }

        $spreadsheet = null;

        try {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $this->writeRow($sheet, 1, self::HEADERS);

            $rowNumber = 2;
            foreach ($this->userRepository->findAllForExport() as $row) {
                $this->writeRow($sheet, $rowNumber, $this->formatRow($row));
                ++$rowNumber;
            }

            $writer = new Xlsx($spreadsheet);
            $writer->setPreCalculateFormulas(false);
            $writer->save($filePath);

            $downloadName = sprintf(
                'usuarios_e_vinculos_%s.xlsx',
                $this->clock->now()->format('Y-m-d_H-i-s')
            );
            $response = new BinaryFileResponse($filePath, headers: ['Content-Type' => self::CONTENT_TYPE]);
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $downloadName);
            $response->deleteFileAfterSend(true);

            return $response;
        } catch (Throwable $exception) {
            if (is_file($filePath)) {
                unlink($filePath);
            }

            throw $exception;
        } finally {
            $spreadsheet?->disconnectWorksheets();
        }
    }

    private function createExportDirectory(): void
    {
        if (is_dir($this->exportDirectory)) {
            return;
        }

        if (!mkdir($this->exportDirectory, 0775, true) && !is_dir($this->exportDirectory)) {
            throw new RuntimeException(sprintf(
                'Não foi possível criar o diretório de exportação "%s".',
                $this->exportDirectory
            ));
        }
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    private function formatRow(array $row): array
    {
        $agentExtraFields = $this->decodeJsonObject($row['agent_extra_fields'] ?? null);
        $organizationExtraFields = $this->decodeJsonObject($row['organization_extra_fields'] ?? null);
        $organizationId = $this->stringValue($row['organization_id'] ?? null);
        $organizationName = $this->stringValue($row['organization_name'] ?? null);
        $organizationType = $this->stringValue($row['organization_type'] ?? null);

        $municipality = $this->stringValue(
            $organizationExtraFields['municipality'] ?? $organizationExtraFields['city_name'] ?? null
        );
        if (
            '' !== $organizationId
            && OrganizationTypeEnum::MUNICIPIO->value === $organizationType
            && '' === $municipality
        ) {
            $municipality = $organizationName;
        }

        return [
            $this->stringValue($row['user_id'] ?? null),
            $this->stringValue($row['user_name'] ?? null),
            $this->stringValue($row['social_name'] ?? null),
            $this->stringValue($row['email'] ?? null),
            $this->formatStatus($row['status'] ?? null),
            $this->formatRoles($row['roles'] ?? null),
            $this->formatDate($row['user_created_at'] ?? null),
            $this->formatDate($row['user_updated_at'] ?? null),
            $this->stringValue($row['agent_id'] ?? null),
            $this->stringValue($row['agent_name'] ?? null),
            $this->stringValue($agentExtraFields['cpf'] ?? null),
            $this->stringValue($agentExtraFields['cargo'] ?? null),
            $organizationId,
            $organizationName,
            $this->formatOrganizationType($organizationType),
            $this->stringValue($organizationExtraFields['cnpj'] ?? null),
            $municipality,
            $this->stringValue($organizationExtraFields['uf'] ?? $organizationExtraFields['state'] ?? null),
            $this->formatOwner($organizationId, $row['owner_id'] ?? null, $row['agent_id'] ?? null),
        ];
    }

    /**
     * @param list<string> $values
     */
    private function writeRow(Worksheet $sheet, int $rowNumber, array $values): void
    {
        foreach ($values as $columnOffset => $value) {
            $sheet->setCellValueExplicit(
                [$columnOffset + 1, $rowNumber],
                $value,
                DataType::TYPE_STRING
            );
        }
    }

    private function formatStatus(mixed $status): string
    {
        return match ($this->stringValue($status)) {
            UserStatusEnum::ACTIVE->value => 'Ativo',
            UserStatusEnum::BLOCKED->value => 'Bloqueado',
            UserStatusEnum::AWAITING_CONFIRMATION->value => 'Aguardando confirmação',
            default => '',
        };
    }

    private function formatRoles(mixed $encodedRoles): string
    {
        $roles = [];
        if (is_string($encodedRoles)) {
            $decodedRoles = json_decode($encodedRoles, true);
            if (is_array($decodedRoles)) {
                foreach ($decodedRoles as $role) {
                    if (is_string($role) && !in_array($role, $roles, true)) {
                        $roles[] = $role;
                    }
                }
            }
        }

        if (!in_array(UserRolesEnum::ROLE_USER->value, $roles, true)) {
            $roles[] = UserRolesEnum::ROLE_USER->value;
        }

        return implode('; ', array_map(
            static fn (string $role): string => match ($role) {
                UserRolesEnum::ROLE_ADMIN->value => 'Administrador Geral',
                UserRolesEnum::ROLE_MANAGER->value => 'Gestor',
                UserRolesEnum::ROLE_COMPANY->value => 'Administrador de Empresa',
                UserRolesEnum::ROLE_MUNICIPALITY->value => 'Administrador de Município',
                UserRolesEnum::ROLE_SUPPORT->value => 'Suporte',
                UserRolesEnum::ROLE_USER->value => 'Usuário Padrão',
                UserRolesEnum::ROLE_CAIXA->value => 'Visualizador Caixa',
                default => $role,
            },
            $roles
        ));
    }

    private function formatOrganizationType(string $type): string
    {
        return match ($type) {
            OrganizationTypeEnum::MUNICIPIO->value => 'Município',
            OrganizationTypeEnum::EMPRESA->value => 'Empresa',
            OrganizationTypeEnum::ENTIDADE->value => 'Entidade',
            OrganizationTypeEnum::OSC->value => 'OSC',
            OrganizationTypeEnum::UNDEFINED->value => 'Indefinida',
            default => '',
        };
    }

    private function formatDate(mixed $date): string
    {
        $date = $this->stringValue($date);

        return '' === $date ? '' : (new DateTimeImmutable($date))->format('d/m/Y H:i:s');
    }

    private function formatOwner(string $organizationId, mixed $ownerId, mixed $agentId): string
    {
        if ('' === $organizationId) {
            return '';
        }

        $agentId = $this->stringValue($agentId);

        return '' !== $agentId && $this->stringValue($ownerId) === $agentId ? 'Sim' : 'Não';
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonObject(mixed $json): array
    {
        if (!is_string($json) || '' === $json) {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
    }
}
