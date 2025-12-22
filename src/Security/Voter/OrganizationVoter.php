<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Organization;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

final class OrganizationVoter extends AbstractVoter
{
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

        // Admin, Manager e Support podem visualizar qualquer organização
        if ($attribute === 'get' || $attribute === 'get_form') {
            return $this->isUserAdminOrManagerOrSupport($user) || $user == $subject->getOwner()->getUser();
        }

        // Apenas Admin, Manager e o owner podem editar
        if ($attribute === 'edit') {
            return $this->isUserAdmin($user) || $this->isUserManager($user) || $user == $subject->getOwner()->getUser();
        }

        return $this->isUserAdmin($user) || $user == $subject->getOwner()->getUser();
    }
}
