<?php

declare(strict_types=1);

/**
 * Derafu: UBL - One Language for Every Business Document.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsUbl\Signature;

use Derafu\Certificate\Service\CertificateFaker;
use Derafu\Certificate\Service\CertificateLoader;
use Derafu\Signature\Contract\SignatureGeneratorInterface;
use Derafu\Signature\Contract\SignatureValidatorInterface as XmlSignatureValidatorInterface;
use Derafu\Signature\Service\SignatureGenerator;
use Derafu\Signature\Service\SignatureValidator as XmlSignatureValidator;
use Derafu\Ubl\Contract\SignatureValidatorInterface;
use Derafu\Ubl\Contract\SignerInterface;
use Derafu\Ubl\Service\SignatureValidator;
use Derafu\Ubl\Service\Signer;
use Derafu\Xml\Contract\XmlServiceInterface;
use Derafu\Xml\Service\XmlDecoder;
use Derafu\Xml\Service\XmlEncoder;
use Derafu\Xml\Service\XmlService;
use Derafu\Xml\Service\XmlValidator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

/**
 * The optional configuration of the signature: with the services of
 * `derafu/signature` that the app has, the container builds the signer and the
 * validator, and they sign and validate a document.
 */
#[CoversNothing]
final class SignatureServicesTest extends TestCase
{
    #[Test]
    public function theConfigurationBuildsTheSignerAndTheValidatorThatSignAndValidateADocument(): void
    {
        $root = dirname(__DIR__, 3);
        $container = new ContainerBuilder();
        $container->register(XmlServiceInterface::class, XmlService::class)->setArguments([
            new Definition(XmlEncoder::class),
            new Definition(XmlDecoder::class),
            new Definition(XmlValidator::class),
        ]);
        $container->register(SignatureGeneratorInterface::class, SignatureGenerator::class)
            ->setArguments([new Reference(XmlServiceInterface::class)]);
        $container->register(XmlSignatureValidatorInterface::class, XmlSignatureValidator::class)
            ->setArguments([new Reference(SignatureGeneratorInterface::class), new Reference(XmlServiceInterface::class)]);
        $loader = new YamlFileLoader($container, new FileLocator($root . '/resources/config'));
        $loader->load('ubl-services.yaml');
        $loader->load('ubl-signature-services.yaml');
        foreach ($container->getDefinitions() as $definition) {
            $definition->setPublic(true);
        }
        $container->compile();

        $signer = $container->get(SignerInterface::class);
        $validator = $container->get(SignatureValidatorInterface::class);
        $this->assertInstanceOf(Signer::class, $signer);
        $this->assertInstanceOf(SignatureValidator::class, $validator);

        $xml = (string) file_get_contents($root . '/tests/fixtures/2.1/doc/xml/Invoice/Invoice_001_official_example.xml');
        $certificate = (new CertificateFaker(new CertificateLoader()))->createFake();
        $signed = $signer->sign($xml, $certificate, ['version' => '2.1']);

        $this->assertTrue($validator->validate($signed)->isValid());
    }
}
