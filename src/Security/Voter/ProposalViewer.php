<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Initiative;
use App\Enum\StatusProposalEnum;
use App\Enum\UserRolesEnum;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;

class ProposalViewer extends Voter
{
    public const VIEW = 'view_proposal';
    public const EDIT = 'edit_proposal';
    public const DELETE = 'delete_proposal';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE])
            && $subject instanceof Initiative;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof UserInterface) {
            return false;
        }

        /** @var Initiative $proposal */
        $proposal = $subject;

        // Admins podem ver/editar/deletar tudo
        if (in_array(UserRolesEnum::ROLE_ADMIN->value, $user->getRoles())) {
            return true;
        }

        // Se é ROLE_CAIXA, verificar restrições
        if (in_array(UserRolesEnum::ROLE_CAIXA->value, $user->getRoles())) {
            return $this->canCaixaAccess($attribute, $proposal);
        }

        // Outras roles têm acesso padrão (sem restrição)
        return true;
    }

    private function canCaixaAccess(string $attribute, Initiative $proposal): bool
    {
        // ROLE_CAIXA só pode VER (não editar, deletar)
        if (self::VIEW !== $attribute) {
            return false;
        }

        // Obter status da proposta
        $status = $proposal->getExtraFields()['status'] ?? null;

        // ROLE_CAIXA só visualiza propostas SELECIONADA e CLASSIFICADA
        return StatusProposalEnum::isRanked((string) $status);
    }
}
