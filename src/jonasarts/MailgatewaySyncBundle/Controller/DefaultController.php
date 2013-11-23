<?php

namespace jonasarts\MailgatewaySyncBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

use jonasarts\MailgatewaySyncBundle\Entity\Domain;

class DefaultController extends Controller
{
    private function removeExpiredDomains($config)
    {
        $msg = "<h1>Delete</h1>\n";

        $default_em = $this->get('doctrine')->getManager();

        $d = new \DateTime("-1 hour");

        // expired
        $domains = $default_em->createQuery(
            'SELECT d 
            FROM jonasartsMailgatewaySyncBundle:Domain d 
            WHERE d.last_sync < :lastsync 
            ORDER BY d.domainname ASC'
            )
            ->setParameter('lastsync', $d->format('Y-m-d H:i:s'))
            ->getResult();;

        if ($domains) {
            $msg .= "Count = ". count($domains) ."<br><br>\n";

            foreach ($domains as $domain) {
                //var_dump($domain);

                // "DELETE FROM `postfix_relay_domain` WHERE `id` = ".$lo_row->id.";<br />\n";
                // "DELETE FROM `postfix_transport` WHERE `id` = ".$lo_row->id.";<br />\n";
                // "DELETE FROM `sync` WHERE `id` = ".$lo_row->id.";<br />\n";

                $msg .= "Delete domain (" . $domain->getId() . ") " . $domain->getDomainname() . " expired at " . $domain->getLastSync()->format('Y-m-d H:i:s') . "<br>\n";

                if ($config['remove']) {
                    $default_em->remove($domain);
                    $default_em->flush();
                }
            }
        }

        return $msg;
    }

    private function syncDomains($config)
    {
        $msg = "<h1>Insert & Update</h1>\n";

        $default_em = $this->get('doctrine')->getManager();
        $master_em = $this->get('doctrine')->getManager('master');

        /*
        $query = "SELECT cmo.id, cmo.type, cmo.obj_name, ps.name, cms.name ".
            "FROM cmail_object AS cmo ".
            "INNER JOIN plesk_server AS ps ON cmo.plesk_server_id = ps.id ".
            "INNER JOIN plesk_server AS cms ON cmo.cmail_server_id = cms.id ".
            "WHERE cmo.type <> 'client' ".
            "ORDER BY cmo.id";

        $query = "SELECT `sync`.*, `postfix_relay_domain`.`domain` ".
            "FROM `sync` ".
            "LEFT JOIN `postfix_relay_domain` ON `sync`.`id` = `postfix_relay_domain`.`id` ".
            "WHERE `lastrun` < %s ".
            "ORDER BY `sync`.`master_id`";
        */

        if ($config['mode'] == 'ppa') { // ppa mailgateway
            $query = "SELECT domains.id, domains.name ".
                "FROM domains ".
                "INNER JOIN dns_recs ON domains.dns_zone_id = dns_recs.dns_zone_id ".
                "WHERE dns_recs.type = 'MX' AND dns_recs.val = :mailgateway ".
                "GROUP BY domains.id ".
                "ORDER BY domains.name ASC";
        } else { // custom mailgateway
            $query = "SELECT domains.id, domains.name, domains.destination ".
                "FROM domains ".
                "ORDER BY domains.name ASC";
        }

        // query master server
        $connection = $master_em->getConnection();
        $statement = $connection->prepare($query);
        if ($config['mode'] == 'ppa') {
            $statement->bindValue('mailgateway', $config['gateway_server'].'.');
        }
        $result = $statement->execute();

        //echo "Query = ". $query ."<br>";

        if ($result) {
            $records = $statement->fetchAll();
            
            $msg .= "Count = ". count($records) ."<br><br>\n";
            
            foreach ($records as $record) {
                //var_dump($record);

                $domain = $default_em->createQuery(
                    'SELECT d 
                    FROM jonasartsMailgatewaySyncBundle:Domain d
                    WHERE d.master_id = :id'
                    )
                    ->setParameter('id', $record['id'])
                    ->getOneOrNullResult();

                // load checksum
                // $domain->getChecksum();

                // load destination server
                if ($config['mode'] == 'custom') {
                    $record['destination'] = $config['postfix_mailsever'];
                }

                if ($domain) { // domain record found -> update

                    $csr = hash('md5', $record['name']); // remote cs
                    $csl = $domain->getChecksum(); // local cs

                    if ($csr != $csl) { // update domain.domainname & domain.destination & domain.last_sync

                        $msg .= "Update " . $record['id'] . " domain " . $record['name'] . " transport smtp:[".$record['destination']."]<br>\n";

                        // "UPDATE postfix_relay_domain SET domain = %s WHERE master_id = %d"
                        // "UPDATE postfix_transport SET domain = %s, destination = 'smtp:[%s]' WHERE master_id = %d"

                        $domain->setDomainname($record['name']);
                        $domain->setMailservername($record['destination']);
                        $domain->setLastSync(new \DateTime());
                        $default_em->persist($domain);
                        $default_em->flush();

                    } else { // only update domain.last_sync

                        $d = new \DateTime();
                        $msg .= "Update domain (" . $record['id'] . ") " . $record['name'] . " last sync " . $d->format('Y-m-d H:i:s') . "<br>\n";

                        // "UPDATE `sync` SET `lastrun` = %s WHERE `master_id` = %s"

                        $domain->setLastSync(new \DateTime());
                        $default_em->persist($domain);
                        $default_em->flush();
                    }
                } else { // no domain record -> insert

                    $msg .= "Insert domain (" . $record['id'] . ") " . $record['name'] . " transport smtp:[".$record['destination']."]<br>\n";

                    // "INSERT INTO postfix_relay_domain (master_id, domain) VALUES (%d, '%s)"
                    // "INSERT INTO postfix_transport (master_id, domain, destination) VALUES (%d, %s, 'smtp:[%s]')"
                    // "INSERT INTO `sync` (`master_id`, `checksum`, `lastrun`) VALUES (%s, %s, %s)"

                    $domain = Domain::create($record);
                    $default_em->persist($domain);
                    $default_em->flush();
                }
            }
        }

        return $msg;
    }

    private function showExpiredDomains()
    {
        $config = array();
        $config['remove'] = false;

        return $this->removeExpiredDomains($config);
    }

    private function deleteExpiredDomains()
    {
        $config = array();
        $config['remove'] = true;

        return $this->removeExpiredDomains($config);
    }

    private function syncCustomDomains()
    {
        $config = array();
        $config['mode'] = 'custom'; // custom / ppa
        $config['gateway_server'] = 'mx3.webfinity.ch'; // not needed
        $config['postfix_mailsever'] = 'ct202.webfinity.ch'; // fallback value only

        return $this->syncDomains($config);
    }

    private function syncPPADomains()
    {
        $config = array();
        $config['mode'] = 'ppa'; // custom / ppa
        $config['gateway_server'] = 'mx1.webfinity.ch';
        $config['postfix_mailsever'] = 'ct202.webfinity.ch';

        return $this->syncDomains($config);
    }

    private function syncMailScanner()
    {

    }

    private function syncSpamAssassin()
    {

    }

    /**
     * @Route("/sync-ppa", name="sync-ppa")
     * @Template("jonasartsMailgatewaySyncBundle:Default:syncDomains.html.twig")
     */
    public function syncPPADomainsAction()
    {
        $msg = $this->syncPPADomains();
        
        return array('msg' => $msg);
    }

    /**
     * @Route("/sync-custom", name="sync-custom")
     * @Template("jonasartsMailgatewaySyncBundle:Default:syncDomains.html.twig")
     */
    public function syncCustomDomainsAction()
    {
        $msg = $this->syncCustomDomains();

        return array('msg' => $msg);
    }

    /**
     * @Route("/show-expired", name="show_expired")
     * @Template()
     */
    public function showExpiredDomainsAction()
    {
        $msg = $this->showExpiredDomains();

        return array('msg' => $msg);
    }

    /**
     * @Route("/delete-expired", name="delete_expired")
     * @Template()
     */
    public function deleteExpiredDomainsAction()
    {
        $msg = $this->deleteExpiredDomains();

        return array('msg' => $msg);
    }
}
