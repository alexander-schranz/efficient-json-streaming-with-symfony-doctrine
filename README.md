# Efficient JSON Streaming with Symfony and Doctrine

After reading a tweet about we provide only a few items (max. 100) over our
JSON APIs but providing 4k images for our websites.  I did think about why is 
this the case.

The main difference first we need to know about how images are streamed.
On webservers today is mostly the sendfile feature used. Which is very
efficient as it can stream a file chunk by chunk and don't  need to load
the whole data.

So I'm asking myself how we can achieve the same mechanisms for our
JSON APIs, with a little experiment.

As an example we will have a look at a basic entity which has the
following fields defined:

 - id: int
 - title: string
 - description: text

The response of our API should look like the following:

```json
{
  "_embedded": {
    "articles": [
      {
        "id": 1,
        "title": "Article 1",
        "description": "Description 1\nMore description text ...",
      },
      ...
    ]
  } 
}
```

Normally to provide this API we would do something like this:

```php
<?php

namespace App\Controller;

use App\Entity\Article;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ArticleListAction
{
    public function __invoke(EntityManagerInterface $entityManager): Response
    {
        $articles = $this->findArticles($entityManager);

        return JsonResponse::fromJsonString(json_encode([
            'embedded' => [
                'articles' => $articles,
            ],
            'total' => 100_000,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    // normally this method would live in a repository
    private function findArticles(EntityManagerInterface  $entityManager): iterable
    {
        $queryBuilder = $entityManager->createQueryBuilder();
        $queryBuilder->from(Article::class, 'article');
        $queryBuilder->select('article.id')
            ->addSelect('article.title')
            ->addSelect('article.description');

        return $queryBuilder->getQuery()->getResult();
    }
}
```

In most cases we will add some pagination to the endpoint so our response are not too big.

## Making the api more efficient

But there is also a way how we can stream this response in an efficient way.

First of all we need to adjust how we load the articles. This can be done by replace
the `getResult` with the more efficient [`toIterable`](https://www.doctrine-project.org/projects/doctrine-orm/en/2.9/reference/batch-processing.html#iterating-results):

```diff
-        return $queryBuilder->getQuery()->getResult();
+        return $queryBuilder->getQuery()->toIterable();
```

Still the whole JSON need to be in the memory to send it. So we need also refactoring
how we are creating our response. We will replace our `JsonResponse` with the 
[`StreamedResponse`](https://symfony.com/doc/6.0/components/http_foundation.html#streaming-a-response) object.

```php
return new StreamedResponse(function() use ($articles) {
    // stream json
}, 200, ['Content-Type' => 'application/json']);
```

But the `json` format is not the best format for streaming, so we need to add some hacks
so we can make it streamable.

First we will create will define the basic structure of our JSON this way:

```php
$jsonStructure = json_encode([
    'embedded' => [
        'articles' => ['__REPLACES_ARTICLES__'],
    ],
    'total' => 100_000,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
```

Instead of the `$articles` we are using a placeholder which we use to split the string into
a `$before` and `$after` variable:

```php
[$before, $after] = explode('"__REPLACES_ARTICLES__"', $jsonStructure, 2);
```

Now we are first sending the `$before`:

```php
echo $before . PHP_EOL;
```

Then we stream the articles one by one to it here we need to keep the comma in mind which
we need to add after every article but not the last one:

```php
foreach ($articles as $count => $article) {
    if ($count !== 0) {
        echo ',' . PHP_EOL; // if not first element we need a separator
    }

    echo json_encode($article, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
```

Also we will add an additional `flush` after every 500 elements:

```php
if ($count % 500 === 0 && $count !== 100_000) { // flush response after every 500
    flush();
}
```

After that we will also send the `$after` part:

```php
echo PHP_EOL;
echo $after;
```

## The result

So at the end the whole action looks like the following:

```php
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
```

The metrics for 100000 Articles:

|                    | Old Implementation | New Implementation |
|--------------------|--------------------|--------------------|
| Memory Usage       | 49.53 MB           | 2.10 MB            |
| Memory Usage Peak  | 59.21 MB           | 2.10 MB            |
| Time to first Byte | 478ms              | 28ms               |
 | Time               | 2.335 s            | 0.584 s            |

This way we did not only reduce the memory usage on our server
also we did make the response faster. The memory usage was
measured here with `memory_get_usage` and `memory_get_peak_usage`.
The "Time to first Byte" by the browser value and response times
over curl.

## Reading Data in javascript

As we stream the data we should also make our JavaScript on the other
end the same way - so data need to read in streamed way.

Here I'm just following the example from the [Fetch API Processing a text file line by line](https://developer.mozilla.org/en-US/docs/Web/API/Fetch_API/Using_Fetch#processing_a_text_file_line_by_line)

So if we look at our [`script.js`](public/script.js) we split the object
line by line and append it to our table. This method is definitely not the
way how JSON should be read and parsed. It should only be shown as example
how the response could be read from a stream.

## Conclusion

The implementation looks a little hacky for maintainability it could
be moved into its own Factory which creates this kind of response.

Example:

```php
return StreamedResponseFactory::create(
    [
        'embedded' => [
            'articles' => ['__REPLACES_ARTICLES__'],
        ],
        'total' => 100_000,
    ],
    ['____REPLACES_ARTICLES__' => $articles]
);
```

The JavaScript part something is definitely not ready for production
and if used you should probably creating your own content-type e.g.:
`application/json+stream`.  So you are parsing the json this way 
only when you know it is really in this line by line format.
There maybe better libraries like [`JSONStream`](https://www.npmjs.com/package/JSONStream)
to read data but at current state did test them out. Let me know
if somebody has experience with that and has solutions for it.

Atleast what I think everybody should use for providing lists
is to use [`toIterable`](https://www.doctrine-project.org/projects/doctrine-orm/en/2.9/reference/batch-processing.html#iterating-results) when possible for your lists when loading
your data via Doctrine and and select specific fields instead
of using the `ORM` to avoid hydration process to object. 

Let me know what you think about this experiment and how you currently are
providing your JSON data.

The whole experiment here can be checked out and test yourself via [this repository](https://github.com/alexander-schranz/efficient-json-streaming-with-symfony-doctrine).

Attend the discussion about this on [Twitter](https://twitter.com/alex_s_/status/1488314080381313025).

## Update 2022-09-27

Added a [StreamedJsonRepsonse](src/Controller/StreamedJsonResponse.php) class and 
try to contribute this implementation to the Symfony core.

[https://github.com/symfony/symfony/pull/47709](https://github.com/symfony/symfony/pull/47709)
