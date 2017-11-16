<?php

namespace Sharelov\Shortener;

use Sharelov\Shortener\Exceptions\NonExistentHashException;
use Sharelov\Shortener\Repositories\ShortLinkRepository;
use Sharelov\Shortener\Utilities\UrlHasher;

class ShortenerService
{
    /**
     * The short links repository
     * @var null | SortLinkRepository
     */
    protected $linkRepo = null;

    /**
     * The url hasher utility
     * @var null | UrlHasher
     */
    private $urlHasher = null;

    public function __construct(ShortLinkRepository $linkRepo, UrlHasher $urlHasher)
    {
        // Initialize our service class dependencies
        $this->linkRepo = $linkRepo;
        $this->urlHasher = $urlHasher;
    }

    /**
     * Make a short link in the database and return the hash.
     * @param  string $url           The url we want to get a short link for
     * @param  string $expires_at    Datetime string following ISO-8601: YYYY-MM-DD hh:mm:ss
     * @param  string $relation_type String representing the relation type if this will be
     *                               associated to anything. Usually the classname of the model.
     * @param  integer $relation_id  The id of the relation this link will be asociated with
     * @return string                The hash generated by $this->makeHash()
     */
    public function make($url, $expires_at = null, $relation_type = null, $relation_id = null)
    {
        return $this->makeHash($url, $expires_at, $relation_type, $relation_id);
    }

    /**
     * Fetch a url by its given hash from the database via the repository
     * @param  string $hash The hash generated for the url you are looking for
     * @return string       The url if one was found and no exceptioin is thrown
     * @throws NonExistentHashException If no link is found for given hash.
     */
    public function getUrlByHash($hash)
    {
        $link = $this->linkRepo->byHash($hash);

        if (!$link || $this->linkRepo->expired($link)) {
            throw new NonExistentHashException();
        }
        return $link->url;
    }

    /**
     * Make a hash for a url and store it in the database
     * @param  string $url        Url to get the hash for
     * @param  string $expires_at Datetime string following ISO-8601: YYYY-MM-DD hh:mm:ss
     * @return string $hash       The unique hash generated
     */
    private function makeHash($url, $expires_at = null, $relation_type = null, $relation_id = null)
    {
        // first, generate a unique hash for this url (5 is default length)
        $hash = $this->urlHasher->make();
        
        if ($this->getUrlByHash($hash)) {
            $length = 5; // use default hash starting length
            $tries = 1; // we already tried once
            do {
                $hash = $this->urlHasher->make($length);
                $tries++;
                if ($tries > 3) {
                    // add one more char to hash and reset
                    // attempts counter to generate unique
                    // hash faster and not sit on this loop
                    // eternally
                    $length++;
                    $tries = 1;
                }
            } while ($this->getUrlByHash($hash));
        }

        $expires_at ? $expires = true : $expires = false;

        $relation_id = (is_numeric($relation_id) ? $relation_id : null);

        $data = compact('url', 'hash', 'expires_at', 'expires', 'relation_type', 'relation_id');

        \Event::fire('ShortLink.creating', [$data]);
        $this->linkRepo->create($data);

        return $hash;
    }
}
