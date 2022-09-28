<?php

namespace App\Controller;

use App\Entity\Article;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

class ArticleListSymfonyAction
{
    public function __invoke(EntityManagerInterface  $entityManager): Response
    {
        $articles = $this->findArticles($entityManager);

        return new StreamedJsonResponse([
            'embedded' => [
                'articles' => '__REPLACES_ARTICLES__',
            ],
            'total' => 100_000,
        ], [
            '__REPLACES_ARTICLES__' => $articles,
        ]);
    }

    private function findArticles(EntityManagerInterface  $entityManager): iterable
    {
        $queryBuilder = $entityManager->createQueryBuilder();
        $queryBuilder->from(Article::class, 'article');
        $queryBuilder->select('article.id')
            ->addSelect('article.title')
            ->addSelect('article.description');

        return $queryBuilder->getQuery()->toIterable();
    }
}
