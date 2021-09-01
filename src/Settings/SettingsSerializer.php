<?php


namespace Paracetamol\Settings;


use Doctrine\Common\Annotations\AnnotationReader;
use Symfony\Component\Serializer\Encoder\YamlEncoder;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AnnotationLoader;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

class SettingsSerializer implements ISettingsSerializer
{
    protected Serializer $serializer;

    protected NameConverterInterface $nameConverter;

    public function __construct()
    {
        $classMetadataFactory = new ClassMetadataFactory(new AnnotationLoader(new AnnotationReader()));
        $this->nameConverter = new CamelCaseToSnakeCaseNameConverter();
        $normalizer = new ObjectNormalizer($classMetadataFactory, $this->nameConverter);
        $encoder = new YamlEncoder();

        $this->serializer = new Serializer([$normalizer],[$encoder]);
    }

    public function getSerializer() : Serializer
    {
        return $this->serializer;
    }

    public function getNameConverter() : NameConverterInterface
    {
        return $this->nameConverter;
    }
}
