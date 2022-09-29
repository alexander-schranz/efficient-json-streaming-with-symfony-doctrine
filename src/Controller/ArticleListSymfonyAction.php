<?php

namespace App\Controller;

use App\Entity\Article;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

class ArticleListSymfonyAction
{
    public function __invoke(EntityManagerInterface  $entityManager): Response
    {
        return new StreamedJsonResponse([
            'embedded' => [
                'articles' => $this->findArticles($entityManager),
            ],
            'total' => 100_000,
        ]);
    }

    private function findArticles(EntityManagerInterface  $entityManager): \Generator
    {
        $queryBuilder = $entityManager->createQueryBuilder();
        $queryBuilder->from(Article::class, 'article');
        $queryBuilder->select('article.id')
            ->addSelect('article.title')
            ->addSelect('article.description');

        return $queryBuilder->getQuery()->toIterable();
    }
}
