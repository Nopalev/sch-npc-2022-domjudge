<?php declare(strict_types=1);
namespace App\Security;

use App\Entity\Role;
use App\Entity\Team;
use App\Entity\TeamAffiliation;
use App\Entity\TeamCategory;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Its\Sso\OpenIDConnectClient;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Guard\AbstractGuardAuthenticator;

class MyITSSSOAuthenticator extends AbstractGuardAuthenticator
{
    private $params;
    private $em;
    private $security;

    public function __construct(
        ContainerBagInterface $params,
        EntityManagerInterface $em,
        Security $security
    )
    {
        $this->params = $params;
        $this->em = $em;
        $this->security = $security;
    }

    /**
     * Called on every request to decide if this authenticator should be
     * used for the request. Returning `false` will cause this authenticator
     * to be skipped.
     */
    public function supports(Request $request): bool
    {
        // if there is already an authenticated user (likely due to the session)
        // then return null and skip authentication: there is no need.
        return !$this->security->getUser() && $request->attributes->get('_route') === 'oidc';
    }

    /**
     * Called on every request. Return whatever credentials you want to
     * be passed to getUser() as $credentials.
     */
    public function getCredentials(Request $request)
    {
        return true;
    }

    public function getUser($credentials, UserProviderInterface $userProvider): ?UserInterface
    {
        $oidc = new OpenIDConnectClient(
            $this->params->get('openid.provider'), // authorization_endpoint
            $this->params->get('openid.client_id'), // Client ID
            $this->params->get('openid.client_secret') // Client Secret
        );

        $oidc->setRedirectURL($this->params->get('openid.redirect_uri')); // must be the same as you registered
        $oidc->addScope($this->params->get('openid.scope')); //must be the same as you registered

        if($this->params->get('kernel.environment') === 'dev') {
            // remove this if in production mode
            $oidc->setVerifyHost(false);
            $oidc->setVerifyPeer(false);
        }
        $oidc->authenticate(); //call the main function of myITS SSO login

        $_SESSION['id_token'] = $oidc->getIdToken(); // must be save for check session dan logout proccess
        $userInfo = $oidc->requestUserInfo(); // this will return user information from myITS SSO database

        // return new JsonResponse($userInfo);
        $em = $this->em;

        $nrp = $userInfo->reg_id;
        $user = $em->getRepository(User::class)->findOneBy(['username' => $nrp]);

        if (!$user) {
            $user = new User();
            $teamRole = $this->em->getRepository(Role::class)->findOneBy(['dj_role' => 'team']);
            $user->setUsername($nrp)->addUserRole($teamRole);
        }

        $team = $user->getTeam();
        if (!$team) {
            $team = new Team();
            $participantCategory = $this->em->getRepository(TeamCategory::class)->findOneBy(['categoryid' => 3]);
            $itsAffiliation = $this->em->getRepository(TeamAffiliation::class)->findOneBy(['externalid' => 'its']);
            $team
                ->setIcpcid($nrp)
                ->setCategory($participantCategory)
                ->setAffiliation($itsAffiliation);
        }

        $team
            ->setName($userInfo->name)
            ->setDisplayName($userInfo->name)
            ->setMembers(sprintf("Nama: %s\nNRP: %s", $userInfo->name, $nrp));
        
        $em->persist($team);
        $em->flush();

        $user
            ->setName($userInfo->name)
            ->setEmail($userInfo->email)
            ->setPlainPassword(random_bytes(16))
            ->setEnabled(true)
            ->setTeam($team);
        
        $em->persist($user);
        $em->flush();

        return $user;
    }

    public function checkCredentials($credentials, UserInterface $user): bool
    {
        // Check credentials - e.g. make sure the password is valid.
        // In case of an API token, no credential check is needed.

        // Return `true` to cause authentication success
        return true;
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, $providerKey): ?Response
    {
        // on success, let the request continue
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $data = [
            // you may want to customize or obfuscate the message first
            'message' => strtr($exception->getMessageKey(), $exception->getMessageData())

            // or to translate this message
            // $this->translator->trans($exception->getMessageKey(), $exception->getMessageData())
        ];

        return new JsonResponse($data, Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Called when authentication is needed, but it's not sent
     */
    public function start(Request $request, AuthenticationException $authException = null): Response
    {
        $data = [
            // you might translate this message
            'message' => 'Authentication Required'
        ];

        return new JsonResponse($data, Response::HTTP_UNAUTHORIZED);
    }

    public function supportsRememberMe(): bool
    {
        return false;
    }
}