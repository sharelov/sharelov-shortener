<?php

namespace Sharelov\Shortener;

use Event;
use Log;
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
    protected $urlHasher = null;

    /**
     * Initial length of hashes
     */
    protected $hash_length = null;

    /**
     * maximum attempts at generating unique hash
     */
    protected $max_attempts = null;

    /**
     * Initialize the class instance with what we need to work out the shortlinks
     * @param ShortLinkRepository $linkRepo
     * @param UrlHasher           $urlHasher
     * @return  this instance
     */
    public function __construct(ShortLinkRepository $linkRepo, UrlHasher $urlHasher)
    {
        $this->linkRepo = $linkRepo;
        $this->urlHasher = $urlHasher;
        $this->hash_length = config('hash_length', 5);
        $this->max_attempts = config('max_attempts', 3);
        return $this;
    }

    /**
     * Modify the lengh of the hash programatically
     * @param int $int Desired length of hash
     */
    public function setHashLength($int)
    {
        $this->hash_length = $int;
        return $this;
    }

    /**
     * Allow for the repository to be set
     * @param ShortLinkRepository $linkRepo an instance of the short link repository
     * @return  this instance
     */
    public function setShortLinkRepository(ShortLinkRepository $linkRepo)
    {
        $this->linkRepo = $linkRepo;
        return $this;
    }

    /**
     * Set the url hasher instance
     * @param UrlHasher $urlHasher instance of the url hasher
     * @return  this instance
     */
    public function setUrlHasher(UrlHasher $urlHasher)
    {
        $this->urlHasher = $urlHasher;
        return $this;
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
            return false;
        }
        return $link->url;
    }

    /**
     * Make a hash for a url and store it in the database
     * @param  string $url        Url to get the hash for
     * @param  string $expires_at Datetime string following ISO-8601: YYYY-MM-DD hh:mm:ss
     * @return string $hash       The unique hash generated
     */
    protected function makeHash($url, $expires_at = null, $relation_type = null, $relation_id = null)
    {
        $length = $this->hash_length;
        $hash = $this->urlHasher->make($length);
        
        if ($this->getUrlByHash($hash)) {
            $tries = 1;
            do {
                $hash = $this->urlHasher->make($length);
                $tries++;
                if ($tries > $this->max_attempts) {
                    $length++;
                    $tries = 1;
                }
            } while ($this->getUrlByHash($hash));
        }

        $expires_at ? $expires = true : $expires = false;
        try{
            if (!is_numeric($relation_id)) {
                throw new \Exception("Relation id was not numeric for url: ".$url);
            }
        } catch (\Exception $e) {
            Log::error("ERROR: ".$e->getMessage());
        }
        $data = compact('url', 'hash', 'expires_at', 'expires', 'relation_type', 'relation_id');
        Event::fire($this->linkRepo->getModelClassName().'.creating', [$data]);
        
        return $this->linkRepo->create($data);
    }
}
