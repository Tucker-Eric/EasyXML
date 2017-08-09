<?php

namespace EasyXML;

use SimpleXMLElement;
use Closure;

class Element extends SimpleXMLElement
{
    /**
     * @var array
     */
    protected static $defaults = [
        'options'   => 0,
        'ns'        => '',
        'is_prefix' => false,
        'type'      => 'string'
    ];

    /**
     * @param $file
     * @param array $options
     * @return static
     */
    public static function fromFile($file, array $options = [])
    {
        $opts = array_merge(static::$defaults, $options);

        return simplexml_load_file($file, Element::class, $opts['options'], $opts['ns'], $opts['is_prefix']);
    }

    public static function fromArray(array $arr)
    {
        $keys = array_keys($arr);
        if (count($keys) !== 1) {
            throw new \Exception(
                'The array passed to Element::fromArray should contain exactly 1 root. ' . count($arr) . ' Given.'
            );
        }
        // We can create this using dot notation
        $rootParts = explode('.', $keys[0]);
        // This is our root if we have it so we'll remove it
        $root = array_shift($rootParts);
        // If dont have more parts we get just the array
        // If not set the new key to the nested string without the root and same nested array
        $children = empty($rootParts) ? $arr[$root] : [substr($keys[0], strlen($root) + 1) => $arr[$keys[0]]];

        return static::fromString("<$root></$root>")->addChildren($children);
    }

    /**
     * @param $data
     * @param array $options
     * @return static
     */
    public static function fromString($data, array $options = [])
    {
        $opts = array_merge(static::$defaults, $options);

        return new static($data, $opts['options'], false, $opts['ns'], $opts['is_prefix']);
    }

    /**
     * @param $url
     * @param array $options
     * @return static
     */
    public static function fromUrl($url, array $options = [])
    {
        $opts = array_merge(static::$defaults, $options);

        return new static($url, $opts['options'], true, $opts['ns'], $opts['is_prefix']);
    }

    /**
     * @return bool
     */
    public function hasAttributes()
    {
        return count($this->attributes()) > 0;
    }

    /**
     * @param $val
     * @return $this
     */
    public function setValue($val)
    {
        $this[0] = $val;

        return $this;
    }

    /**
     * We have to allow the child input because it helps us return an array of elements
     * Instead of the first of a collection which is how it does just accessing.
     * @param string|null $child
     * @return array
     */
    public function toArray($child = null)
    {
        $arr = (array)$this;

        return $child ? $arr[$child] : $arr;
    }

    public function each(Closure $callback)
    {
        foreach ($this->toArray() as $key => $value) {
            $callback($key, $value);
        }

        return $this;
    }

    public function removeChild($child)
    {
        if (is_string($child)) {
            $child = $this->$child;
        }

        $dom = dom_import_simplexml($child);
        $dom->parentNode->removeChild($dom);

        return $this;
    }

    /**
     * Get the value of the element
     * @return string
     */
    public function value()
    {
        return (string)$this;
    }

    /**
     * @param array $attrs
     * @return $this
     */
    public function addAttributes(array $attrs)
    {
        foreach ($attrs as $key => $val) {
            $this->addAttribute($key, $val);
        }

        return $this;
    }

    public function hasAttribute($attr)
    {
        return in_array($attr, $this->attributes(), true);
    }

    /**
     * @param array $children
     * @return $this
     */
    public function addChildren(array $children)
    {
        foreach ($children as $key => $val) {
            // Gets the element at the end of the dot notation in the array key
            $child = $this->nestElements($key);
            // If this is an array then we have an array of children elements
            // If it is not an array then we set the value
            if (is_array($val)) {
                // If the first element is an array then we have a collection of repeating elements
                if (isset($val[0]) && is_array($val[0])) {
                    // The first item in the collection was just created in nesting
                    // So we need to fill it with the first item in the array collection
                    // We shift that array item off to fill the first created child then can loop the rest from the parent
                    $child->addChildren(array_shift($val));
                    $parent = $child->parent();
                    // Loop through the rest of them
                    foreach ($val as $index => $collectionItem) {
                        // Add Child and apply attributes from model child
                        // Add the model child's attributes to this child
                        $parent->addChildren([$child->getName() => $collectionItem]);
                    }
                } else {
                    $child->addChildren($val);
                }
            } else {
                // Set the value of the current XML node
                $child->setValue($val);
            }
        }

        return $this;
    }

    public function allNamespaces()
    {
        return $this->getDocNamespaces();
    }

    /**
     * @param null $ns
     * @param bool $is_prefix
     * @return array
     */
    public function attributes($ns = null, $is_prefix = false)
    {
        $attrs = (array)parent::attributes($ns, $is_prefix);

        return isset($attrs['@attributes']) ? $attrs['@attributes'] : $attrs;
    }

    /**
     * @param $node
     * @return $this
     */
    public function append(SimpleXMLElement $node)
    {
        $parent = dom_import_simplexml($this);
        $child = dom_import_simplexml($node);
        $parent->appendChild($parent->ownerDocument->importNode($child, true));

        return $this;
    }

    /**
     * @return static
     */
    public function getParent()
    {
        return $this->xpath('..')[0];
    }

    /**
     * @return Element
     */
    public function parent()
    {
        return $this->getParent();
    }

    /**
     * @param $key
     * @param $val
     * @return static
     */
    public function addNamespace($key, $val)
    {
        dom_import_simplexml($this)->setAttributeNS('http://www.w3.org/2000/xmlns/', "xmlns:$key", $val);

        return $this;
    }


    /**
     * Lets us use notation like 'nodeName[attr=val otherAttr=val]
     * @param $string
     * @return array
     */
    protected function parseNodeString($string)
    {
        $attrs = [];
        // Gets returns everything in the brackets of nodeName[foo=bar raz=baz gar="bar man"]
        if (preg_match('/\[[^\]]+\]/', $string, $matches)) {
            // This strips the brackets from the node so we can get the root node without attributes
            $string = str_replace($matches[0], '', $string);
            // Get all the key value pairs from the node
            if (preg_match_all('/[\w:]+=(?:"[^"]+"|[\S]+)/', $matches[0], $attributes)) {
                foreach ($attributes[0] as $attr) {
                    list($key, $val) = explode('=', trim($attr, '[]'));
                    $attrs[$key] = trim($val, '\'"');
                }
            }
        }

        return [$string, $attrs];
    }

    /**
     * @inheritdoc
     */
    public function addAttribute($name, $value = null, $namespace = null)
    {
        parent::addAttribute($name, $value, $namespace ?: $this->getNamespace($name));
    }

    /**
     * @inheritdoc
     */
    public function addChild($name, $value = null, $namespace = null)
    {
        return parent::addChild($name, $value, $namespace ?: $this->getNamespace($name));
    }

    /**
     * @param $item
     * @return bool
     */
    protected function isNamespaced($item)
    {
        return substr_count($item, ':') === 1;
    }

    /**
     * @param string $name
     * @return string|null
     */
    public function getNamespace($name)
    {
        $namespace = null;
        if (!$this->isNamespaced($name)) {
            return $namespace;
        }
        // Extract namespace
        $key = substr($name, 0, strpos($name, ':'));
        $ns = $this->getDocNamespaces(true);

        // Return full uri of namespace
        return array_key_exists($key, $ns) ? $ns[$key] : null;
    }

    /**
     * @param $string
     * @return static
     */
    protected function nestElements($string)
    {
        $nodes = explode('.', $string);
        $parent = $this;
        foreach ($nodes as $index => $node) {
            list($node, $attrs) = $this->parseNodeString($node);

            $parent = $parent->addChild($node);

            foreach ($attrs as $key => $val) {
                $parent->addAttribute($key, $val);
            }
        }

        return $parent;
    }

    /**
     * @param $file
     * @param $options
     * @return SimpleXMLElement
     */
    protected function newFromFile($file, $options)
    {
        return simplexml_load_file(
            $file, Element::class, $options['options'], $options['ns'], $options['is_prefix']
        );
    }

    /**
     * @param $data
     * @param $options
     * @return SimpleXMLElement
     */
    protected function newElement($data, $options)
    {
        return new static(
            $data, $options['options'], $options['type'] === 'url', $options['ns'], $options['is_prefix']
        );
    }
}
