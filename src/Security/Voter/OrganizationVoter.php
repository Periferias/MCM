<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Organization;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

final class OrganizationVoter extends AbstractVoter
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    protected array $actions = [
        'get',
        'get_form',
        'edit',
    ];

    protected string $class = Organization::class;

    /**
     * @param Organization $subject
     */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user) {
            $this->logger->error('OrganizationVoter: No user found');

            return false;
        }

        $roles = implode(', ', $user->getRoles());
        $this->logger->info("OrganizationVoter: User {$user->getEmail()} with roles: {$roles}, attribute: {$attribute}");

        // Admin, Manager, Support, Municipality e Company podem visualizar qualquer organização
        if ('get' === $attribute || 'get_form' === $attribute) {
            $isAdminOrManagerOrSupport = $this->isUserAdminOrManagerOrSupport($user);
            $isMunicipality = $this->isUserMunicipality($user);
            $isCompany = $this->isUserCompany($user);

            $this->logger->info("OrganizationVoter: isAdminOrManagerOrSupport={$isAdminOrManagerOrSupport}, isMunicipality={$isMunicipality}, isCompany={$isCompany}");

            if ($isAdminOrManagerOrSupport || $isMunicipality || $isCompany) {
                $this->logger->info('OrganizationVoter: Access granted by role');

                return true;
            }

            // Verifica se é o owner
            $owner = $subject->getOwner();
            if ($owner && $owner->getUser() && $user->getId()->equals($owner->getUser()->getId())) {
                $this->logger->info('OrganizationVoter: Access granted as owner');

                return true;
            }

            $this->logger->warning('OrganizationVoter: Access denied');

            return false;
        }

        // Apenas Admin, Manager e o owner podem editar
        if ('edit' === $attribute) {
            if ($this->isUserAdmin($user) || $this->isUserManager($user)) {
                return true;
            }

            $owner = $subject->getOwner();
            if ($owner && $owner->getUser() && $user->getId()->equals($owner->getUser()->getId())) {
                return true;
            }

            return false;
        }

        return $this->isUserAdmin($user);
    }
}
