<?php

/* 

CREATE OR REPLACE VIEW postfix_relay_domain AS
SELECT domainname AS domain
FROM domains
ORDER BY domainname ASC;

CREATE OR REPLACE VIEW postfix_transport AS
SELECT domainname AS domain, CONCAT('smtp:[', mailservername,  ']') AS destination
FROM domains
ORDER BY domainname ASC;

 */

namespace jonasarts\MailgatewaySyncBundle\Entity;

use Symfony\Component\Validator\Constraints as Assert;
use Doctrine\ORM\Mapping as ORM;

/**
 * Domain
 * 
 * @ORM\Entity
 * @ORM\Table(name="domains",
 *      uniqueConstraints={@ORM\UniqueConstraint(name="uix_master_id_alias_id", columns={"master_id", "alias_id"}), @ORM\UniqueConstraint(name="uix_domainname", columns={"domainname"})},
 *      indexes={@ORM\Index(name="ix_last_sync", columns={"last_sync"})}
 * )
 */
class Domain
{
    /**
     * @var integer The internal id of the domain
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var integer The master id of the domain
     *
     * @ORM\Column(name="master_id", type="integer")
     */
    private $master_id;

    /**
     * @var integer The alias id of the domain
     * 
     * If the record is a master domain, the alias_id is 0.
     * If alias_id > 0, then the record is an alias domain.
     *
     * @ORM\Column(name="alias_id", type="integer")
     */
    private $alias_id;

    /**
     * @var string The domain name
     *
     * @ORM\Column(name="domainname", type="string", length=255, nullable=false)
     * @Assert\NotBlank
     * @Assert\Length(max=255)
     */
    private $domainname;

    /**
     * @var string The smpt server for the domain
     *
     * @ORM\Column(name="mailservername", type="string", length=255, nullable=false)
     * @Assert\NotBlank
     * @Assert\Length(max=255)
     */
    private $mailservername;

    /**
     * @var \DateTime The timestamp the domain record was the last time updated
     *
     * @ORM\Column(name="last_sync", type="datetime", nullable=false)
     */
    private $last_sync;

    /**
     * Construct
     * 
     */
    public function __construct()
    {
        $this->last_sync = new \DateTime();
    }

    /**
     * Constructor method
     */
    public static function create($record)
    {
        $d = new Domain();
        $d->master_id = 0;
        $d->alias_id = 0;
        $d->domainname = '';
        $d->mailservername = '';

        if (array_key_exists('id', $record)) {
            if (is_numeric($record['id'])) {
                $d->master_id = $record['id'];
            }
        }
        if (array_key_exists('aid', $record)) {
            if (is_numeric($record['aid'])) {
                $d->alias_id = $record['aid'];
            }
        }
        if (array_key_exists('name', $record)) {
            $d->domainname = trim($record['name']);
        }
        if (array_key_exists('destination', $record)) {
            $d->mailservername = trim($record['destination']);
        }

        $d->last_sync = new \DateTime();

        return $d;
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
     * Set master_id
     *
     * @param  integer $master_id
     * @return Domain
     */
    public function setMasterId($master_id)
    {
        $this->master_id = $master_id;

        return $this;
    }

    /**
     * Get master_id
     *
     * @return integer
     */
    public function getMasterId()
    {
        return $this->master_id;
    }

    /**
     * Set alias_id
     *
     * @param  integer $alias_id
     * @return Domain
     */
    public function setAliasId($alias_id)
    {
        $this->alias_id = $alias_id;

        return $this;
    }

    /**
     * Get alias_id
     *
     * @return integer
     */
    public function getAliasId()
    {
        return $this->alias_id;
    }

    /**
     * Set domainname
     *
     * @param  string $domainname
     * @return Domain
     */
    public function setDomainname($domainname)
    {
        $this->domainname = $domainname;

        return $this;
    }

    /**
     * Get domainname
     *
     * @return string
     */
    public function getDomainname()
    {
        return $this->domainname;
    }

    /**
     * Set mailservername
     *
     * @param  string $mailservername
     * @return Domain
     */
    public function setMailservername($mailservername)
    {
        $this->mailservername = $mailservername;

        return $this;
    }

    /**
     * Get mailservername
     *
     * @return string
     */
    public function getMailservername()
    {
        return $this->mailservername;
    }

    /**
     * Set last_sync
     *
     * @param  \DateTime $last_sync
     * @return Domain
     */
    public function setLastSync(\DateTime $last_sync = null)
    {
        $this->last_sync = $last_sync;

        return $this;
    }

    /**
     * Get last_symc
     * 
     * @return \DateTime
     */
    public function getLastSync()
    {
        return $this->last_sync;
    }

    // additional methods

    /**
     * Get checksum
     *
     * @return string
     */
    public function getChecksum()
    {
        return hash('md5', $this->domainname.":".$this->mailservername);
    }
}
