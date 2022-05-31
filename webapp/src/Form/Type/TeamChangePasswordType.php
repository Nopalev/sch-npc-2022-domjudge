<?php declare(strict_types=1);

namespace App\Form\Type;

use App\Service\ConfigurationService;
use App\Service\DOMJudgeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;

class TeamChangePasswordType extends AbstractType
{
    /**
     * @var DOMJudgeService
     */
    protected $dj;

    /**
     * @var ConfigurationService
     */
    protected $config;

    /**
     * @var EntityManagerInterface
     */
    protected $em;

    public function __construct(
        DOMJudgeService $dj,
        ConfigurationService $config,
        EntityManagerInterface $em
    ) {
        $this->dj = $dj;
        $this->em = $em;
        $this->config = $config;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('current_password', PasswordType::class, [
                'required' => true,
                'constraints' => new UserPassword()
            ])
            ->add('password', RepeatedType::class, [
                'type' => PasswordType::class,
                'invalid_message' => 'The password fields must match.',
                'options' => ['attr' => ['class' => 'password-field']],
                'required' => true,
                'first_options'  => ['label' => 'New Password'],
                'second_options' => ['label' => 'Confirm New Password'],
            ]);
    }
}
