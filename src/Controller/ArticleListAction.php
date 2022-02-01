<?php

namespace App\Controller;

use App\Entity\Article;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ArticleListAction
{
    public function __invoke(EntityManagerInterface  $entityManager): Response
    {
        $articles = $this->findArticles($entityManager);

        return new StreamedResponse(function() use ($articles) {
            // defining our json structure but replaces the articles with a placeholder
            $jsonStructure = json_encode([
                'embedded' => [
                    'articles' => ['__REPLACES_ARTICLES__'],
                ],
                'total' => 100_000,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            // split by placeholder
            [$before, $after] = explode('"__REPLACES_ARTICLES__"', $jsonStructure, 2);

            // send first before part of the json
            echo $before . PHP_EOL;

            // stream article one by one as own json
            foreach ($articles as $count => $article) {
                if ($count !== 0) {
                    echo ',' . PHP_EOL; // if not first element we need a separator
                }

                if ($count % 500 === 0 && $count !== 100_000) { // flush response after every 500
                    flush();
                }

                echo json_encode($article, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            // send the after part of the json as last
            echo PHP_EOL;
            echo $after;
        }, 200, ['Content-Type' => 'application/json']);
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
