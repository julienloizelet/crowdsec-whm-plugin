<?php

/**
 * The acquisition form type class.
 *
 * @author    CrowdSec team
 *
 * @see      https://crowdsec.net CrowdSec Official Website
 *
 * @copyright Copyright (c) 2020+ CrowdSec
 * @license   MIT License
 */

declare(strict_types=1);

namespace CrowdSec\Whm\Form;

use CrowdSec\Whm\Acquisition\Config;
use CrowdSec\Whm\Helper\Data as Helper;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\HttpFoundation\Request;

class AcquisitionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $helper = new Helper();

        $version = $helper->getAcquisitionVersion();
        $acquisitionConfig = new Config($version);

        $request = Request::createFromGlobals();
        $hasId = $request->get('id');

        $builder->add(
            'filepath',
            $hasId ? HiddenType::class : TextType::class,
            array_merge(
                ['label' => 'Acquisition file path'],
                $hasId ? [] : ['required' => true, 'help' => 'Relative path to ' . $helper->getAcquisDir()]
            )
        );

        $configurations = $acquisitionConfig->getConfig();
        foreach ($configurations as $source => $configs) {
            foreach ($configs as $name => $data) {
                $inputs = $this->handleConfig($name, $data);
                if ($inputs) {
                    foreach ($inputs as $input) {
                        $options = [
                            'label' => $input['label'] ?? '',
                            'required' => $input['required'] ?? false,
                            'help' => $input['help'] ?? '',
                        ];
                        if (TextareaType::class === $input['class']) {
                            $options['attr'] = ['rows' => 5];
                        } elseif (ChoiceType::class === $input['class']) {
                            $options['choices'] = $input['choices'];
                        }

                        $builder->add(
                            $source . '_' . $input['name'],
                            $input['class'],
                            $options
                        );
                    }
                }
            }
        }

        $builder->add('save', SubmitType::class);
    }

    private function handleConfig(string $name, array $config): array
    {
        return 'map' !== $config['type'] ?
            [$this->handleSingleConfig($name, $config)] :
            array_map(function ($valueName, $data) use ($name) {
                return $this->handleSingleConfig($valueName, $data, $name . '_');
            }, array_keys($config['values']), $config['values']);
    }

    private function handleSingleConfig(string $name, array $config, string $prefix = ''): array
    {
        $typeHandlers = [
            'string' => function () {
                return ['class' => TextType::class];
            },
            'array' => function () {
                return ['class' => TextareaType::class];
            },
            'enum' => function () use ($config) {
                return [
                    'class' => ChoiceType::class,
                    'choices' => array_combine($config['values'], $config['values']),
                ];
            },
            'integer' => function () {
                return ['class' => IntegerType::class];
            },
            'boolean' => function () {
                return [
                    'class' => ChoiceType::class,
                    'choices' => array_combine(['true', 'false'], ['true', 'false']),
                ];
            },
        ];

        $result = isset($typeHandlers[$config['type']]) ? $typeHandlers[$config['type']]() : [];
        $result += [
            'name' => $prefix . $name,
            'label' => $config['label'] ?? '',
            'required' => $config['required'] ?? false,
            'help' => $config['help'] ?? '',
        ];

        return $result;
    }
}