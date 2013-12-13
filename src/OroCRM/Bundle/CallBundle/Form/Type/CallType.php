<?php

namespace OroCRM\Bundle\CallBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;

use OroCRM\Bundle\CallBundle\Form\EventListener\ContactPhoneSubscriber;

class CallType extends AbstractType
{
    private $contactPhoneSubscriber;

    /**
     * Constructor.
     *
     * @param ContactPhoneSubscriber $om
     */
    public function __construct(ContactPhoneSubscriber $contactPhoneSubscriber)
    {
        $this->contactPhoneSubscriber = $contactPhoneSubscriber;
    }

    /**
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->addEventSubscriber($this->contactPhoneSubscriber);

        $builder
            ->add('relatedAccount', 'orocrm_account_select', array('required' => false))
            ->add('subject', 'text', array('required' => true))
            ->add('relatedContact', 'orocrm_contact_select', array('required' => false))
            ->add(
                'contactPhoneNumber',
                'entity',
                array('class' => 'OroCRM\Bundle\ContactBundle\Entity\ContactPhone', 'required' => false)
            )
            ->add('phoneNumber', 'text', array('required' => false, 'attr' => array('class' => 'hide')))
            ->add('notes', 'textarea', array('required' => false))
            ->add('callDateTime', 'oro_datetime', array('required' => true))
            ->add('callStatus', 'hidden', array('property_path' => 'callStatus.status'))
            ->add('duration', 'time', array('required' => false, 'widget' => 'single_text', 'with_seconds' => true))
            ->add(
                'direction',
                'entity',
                array('class' => 'OroCRM\Bundle\CallBundle\Entity\CallDirection', 'required' => true)
            );
    }

    /**
     * @param OptionsResolverInterface $resolver
     */
    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $resolver->setDefaults(
            array(
                'data_class' => 'OroCRM\Bundle\CallBundle\Entity\Call',
                'error_mapping' => array(
                    '.' => 'contactPhoneNumber',
                ),
            )
        );
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'orocrm_call_form';
    }
}