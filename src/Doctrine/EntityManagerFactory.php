<?php

namespace App\Doctrine;

use App\Entity\Article;
use Doctrine\Common\EventManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\Driver\SimplifiedXmlDriver;
use Doctrine\ORM\Tools\ResolveTargetEntityListener;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\Setup;

class EntityManagerFactory
{
    private static ?EntityManagerInterface $entityManager = null;
    private static string $DATABASE_FILE = __DIR__ . '/../../var/test.sqlite';

    public static function getEntityManagerFactory(): EntityManagerInterface
    {
        if (static::$entityManager) {
            return static::$entityManager;
        }

        if (!is_dir(\dirname(static::$DATABASE_FILE))) {
            mkdir(\dirname(static::$DATABASE_FILE), 0777, true);
        }

        $connection = [
            'driver' => 'pdo_sqlite',
            'path' => static::$DATABASE_FILE,
        ];

        $config = Setup::createConfiguration(
            true,
            __DIR__ . '/../../../../var/cache'
        );

        $namespaces = [
            __DIR__ . '/../../config/doctrine' => 'App\Entity',
        ];

        $driver = new SimplifiedXmlDriver($namespaces);

        $config->setMetadataDriverImpl($driver);

        $eventManager = new EventManager();
        $resolveTargetEntityListener = new ResolveTargetEntityListener();
        $eventManager->addEventListener(Events::loadClassMetadata, $resolveTargetEntityListener);
        static::$entityManager = EntityManager::create($connection, $config, $eventManager);

        if (!file_exists(static::$DATABASE_FILE)) {
            $schemaTool = new SchemaTool(static::$entityManager);
            $classes = static::$entityManager->getMetadataFactory()->getAllMetadata();
            $schemaTool->createSchema($classes);

            // load fixtures
            for ($i = 0; $i <= 100_000; ++$i) {
                $article = new Article('Title ' . $i, 'Description ' . $i . PHP_EOL . 'More description text ....');
                static::$entityManager->persist($article);

                if ($i % 100 === 0) {
                    static::$entityManager->flush();
                    static::$entityManager->clear();
                }
            }

            static::$entityManager->flush();
            static::$entityManager->clear();
        }

        return static::$entityManager;
    }
}
