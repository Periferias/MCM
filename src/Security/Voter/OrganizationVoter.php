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
        
        if (!$user) {
            return false;
        }

        // Admin, Manager, Support e Municipality podem visualizar qualquer organização
        if ($attribute === 'get' || $attribute === 'get_form') {
            if ($this->isUserAdminOrManagerOrSupport($user) || $this->isUserMunicipality($user)) {
                return true;
            }
            
            // Verifica se é o owner
            $owner = $subject->getOwner();
            if ($owner && $owner->getUser() && $user->getId()->equals($owner->getUser()->getId())) {
                return true;
            }
            
            return false;
        }

        // Apenas Admin, Manager e o owner podem editar
        if ($attribute === 'edit') {
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
