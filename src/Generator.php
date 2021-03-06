<?php

namespace Bolt\Extension\Bolt\RssFeed;

use Bolt\Storage\Entity\Content;
use Bolt\Storage\EntityManager;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Twig_Environment;

/**
 * RSSFeed Generator.
 *
 * @author Gawain Lynch <gawain.lynch@gmail.com>
 */
class Generator
{
    /** @var Config\Config */
    protected $config;
    /** @var array */
    protected $contentTypes;
    /** @var EntityManager */
    protected $em;
    /** @var Twig_Environment */
    protected $twig;

    /**
     * Constructor.
     *
     * @param Config\Config    $config
     * @param array            $contentTypes
     * @param EntityManager    $em
     * @param Twig_Environment $twig
     */
    public function __construct(Config\Config $config, array $contentTypes, EntityManager $em, Twig_Environment $twig)
    {
        $this->config = $config;
        $this->contentTypes = $contentTypes;
        $this->em = $em;
        $this->twig = $twig;
    }

    /**
     * @param string|null $contentTypeName
     *
     * @return \Twig_Markup
     */
    public function getAtom($contentTypeName = null)
    {
        $context = $this->getContext($contentTypeName);
        /** @var Config\ContentTypeFeed|Config\SiteWideFeed $feedConfig */
        $feedConfig = $context['config'];
        $feed = $this->twig->render($feedConfig->getAtomTemplate(), $context);

        return new \Twig_Markup($feed, 'UTF-8');
    }

    /**
     * @param string|null $contentTypeName
     *
     * @return \Twig_Markup
     */
    public function getFeed($contentTypeName = null)
    {
        $context = $this->getContext($contentTypeName);
        /** @var Config\ContentTypeFeed|Config\SiteWideFeed $feedConfig */
        $feedConfig = $context['config'];
        $feed = $this->twig->render($feedConfig->getFeedTemplate(), $context);

        return new \Twig_Markup($feed, 'UTF-8');
    }

    /**
     * @param string|null $contentTypeName
     *
     * @return array
     */
    protected function getContext($contentTypeName = null)
    {
        $config = $this->getConfig($contentTypeName);
        if ($config->isEnabled() === false) {
            throw new HttpException(Response::HTTP_NOT_FOUND, sprintf('Feed for "%s" not found.', $contentTypeName));
        }

        $content = [];
        $contentTypes = $this->getContentTypes($contentTypeName);
        foreach ($contentTypes as $values) {
            $content = $this->contentArrayAppend($content, $values);
        }
        ksort($content);
        $content = array_slice(array_reverse($content), 0, $config->getFeedRecords());

        return [
            'records'        => $content,
            'content_length' => $config->getContentLength(),
            $contentTypeName => $content,
            'config'         => $config,
        ];
    }

    /**
     * @param string $contentTypeName
     *
     * @return Config\ContentTypeFeed|Config\SiteWideFeed
     */
    protected function getConfig($contentTypeName)
    {
        if ($contentTypeName === null) {
            return $this->config->getSiteWideFeed();
        }

        return $this->config->getContentTypeFeed($contentTypeName);
    }

    /**
     * @param string $contentTypeName
     *
     * @return array
     */
    protected function getContentTypes($contentTypeName)
    {
        $contentTypes = [];
        if ($contentTypeName === null) {
            foreach ($this->config->getSiteWideFeed()->getContentTypes() as $name => $type) {
                $contentTypes[$name] = $this->contentTypes[$type];
            }
        } else {
            $contentTypes[$contentTypeName] = $this->contentTypes[$contentTypeName];
        }

        return $contentTypes;
    }

    /**
     * @param string $contentTypeName
     *
     * @return Content[]|false
     */
    protected function getContent($contentTypeName)
    {
        $config = $this->config->getContentTypeFeed($contentTypeName);
        $repo = $this->em->getRepository($contentTypeName);
        $query = $repo
            ->createQueryBuilder()
            ->where('status = :status')
            ->setParameter('status', 'published')
            ->setMaxResults($config->getFeedRecords())
            ->orderBy('datepublish', 'DESC')
        ;

        return $repo->findWith($query);
    }

    /**
     * @param array $content
     * @param array $contentType
     *
     * @return array
     */
    protected function contentArrayAppend(array $content, array $contentType)
    {
        $newContent = $this->getContent($contentType['slug']);

        if ($newContent === false) {
            return $content;
        }

        foreach ($newContent as $new) {
            $key = $new->getDatepublish()->getTimestamp() . '.' . $new->getId() . '.' . $new->getSlug();
            $content[$key] = $new;
        }

        return $content;
    }
}
