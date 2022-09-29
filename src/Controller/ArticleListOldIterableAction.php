<?php

namespace App\Controller;

use App\Entity\Article;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ArticleListOldIterableAction
{
    public function __invoke(EntityManagerInterface  $entityManager): Response
    {
        $articles = \iterator_to_array($this->findArticles($entityManager));

        return JsonResponse::fromJsonString(json_encode([
            'embedded' => [
                'articles' => $articles,
                'total' => 100_000,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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
