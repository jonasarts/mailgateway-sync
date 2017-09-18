<?php

namespace jonasarts\MailgatewaySyncBundle\Entity;

class Rule
{
    /**
     * @var integer The internal id of the rule
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var string The rule name
     *
     * @ORM\Column(name="name", type="string", length=255, nullable=false)
     * @Assert\NotBlank
     * @Assert\Length(max=255)
     */
    private $name;

    /**
     * @var string The default value for the rule
     *
     * @ORM\Column(name="default", type="string", length=255, nullable=false)
     * @Assert\NotBlank
     * @Assert\Length(max=255)
     */
    private $default;

    /**
     * Constructor method
     */
    public static function create($record)
    {
        $r = new Rule();
        if (array_key_exists('id', $record)) {
            $r->id = $record['id'];
        }
        if (array_key_exists('name', $record)) {
            $r->name = $record['name'];
        }
        if (array_key_exists('default', $record)) {
            $r->default = $record['default'];
        }

        return $r;
    }

    /**
     * Get id
     *
     * @return integer
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set name
     *
     * @param  string $name
     * @return Rule
     */
    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Get name
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Set default
     *
     * @param  string $default
     * @return Rule
     */
    public function setDefault($default)
    {
        $this->default = $default;

        return $this;
    }

    /**
     * Get default
     *
     * @return string
     */
    public function getDefault()
    {
        return $this->default;
    }

    // additional methods

    /**
     * @return string
     */
    public function getFileName()
    {
        return str_replace(" ", ".", strtolower($this->name)).".rules";
    }
}