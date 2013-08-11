<?php

namespace Guzzle\Http\Header;

use Guzzle\Common\ToArrayInterface;

interface HeaderInterface extends ToArrayInterface, \Countable, \IteratorAggregate
{
    /**
     * Convert the header to a string
     *
     * @return string
     */
    public function __toString();

    /**
     * Add a value to the list of header values
     *
     * @param string $value Value to add to the header
     *
     * @return self
     */
    public function add($value);

    /**
     * Get the name of the header
     *
     * @return string
     */
    public function getName();

    /**
     * Change the name of the header
     *
     * @param string $name Name to change to
     *
     * @return self
     */
    public function setName($name);

    /**
     * Remove a specific value from the header
     *
     * @param string $searchValue Value to remove
     *
     * @return self
     */
    public function removeValue($searchValue);

    /**
     * Parse a header containing ";" separated data into an array of associative arrays representing the header
     * key value pair data of the header. When a parameter does not contain a value, but just contains a key, this
     * function will inject a key with a '' string value.
     *
     * @return array
     */
    public function parseParams();
}