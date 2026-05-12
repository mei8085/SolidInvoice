<?php

declare(strict_types=1);

/*
 * This file is part of SolidInvoice project.
 *
 * (c) Pierre du Plessis <open-source@solidworx.co>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace SolidInvoice\ClientBundle\Form\Type;

use SolidInvoice\ClientBundle\Entity\Address;
use SolidInvoice\ClientBundle\Entity\Client;
use SolidInvoice\MoneyBundle\Form\Type\CurrencyType;
use SolidInvoice\TaxBundle\Entity\TaxIdentifier;
use SolidInvoice\TaxBundle\Form\Type\TaxIdentifierType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\LiveComponent\Form\Type\LiveCollectionType;

/**
 * @see \SolidInvoice\ClientBundle\Tests\Form\Type\ClientTypeTest
 */
class ClientType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', null, ['sanitize_html' => true, 'allow_single_quotes' => true]);
        $builder->add('website', UrlType::class, ['required' => false]);

        $builder->add(
            'currencyCode',
            CurrencyType::class,
            [
                'placeholder' => 'client.form.currency.empty_value',
                'required' => false,
            ]
        );

        $builder->add(
            'contacts',
            LiveCollectionType::class,
            [
                'entry_type' => ContactType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'button_delete_options' => [
                    'label_html' => true,
                ],
            ]
        );

        $builder->add(
            'addresses',
            LiveCollectionType::class,
            [
                'entry_type' => AddressType::class,
                'entry_options' => [
                    'data_class' => Address::class,
                ],
                'allow_add' => true,
                'allow_delete' => true,
                'required' => false,
            ]
        );

        $builder->add(
            'taxIdentifiers',
            LiveCollectionType::class,
            [
                'entry_type' => TaxIdentifierType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'required' => false,
            ]
        );

        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event): void {
            $client = $event->getData();

            if (! $client instanceof Client) {
                return;
            }

            if ($client->getTaxIdentifiers()->isEmpty()) {
                $identifier = new TaxIdentifier();
                $identifier->setLabel('VAT');
                $identifier->setPrimary(true);
                $client->addTaxIdentifier($identifier);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Client::class,
            'validation_groups' => ['Default', 'form'],
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'client';
    }
}
