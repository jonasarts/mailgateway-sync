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

                $msg .= "Delete domain (" . $domain->getMasterId() . "/" . $domain->getAliasId() . ") " . $domain->getDomainname() . " expired at " . $domain->getLastSync()->format('Y-m-d H:i:s') . "<br>\n";

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
            $query1 = "SELECT domains.id, domains.name ".
                "FROM domains ".
                "INNER JOIN dns_recs ON domains.dns_zone_id = dns_recs.dns_zone_id ".
                "WHERE dns_recs.type = 'MX' AND dns_recs.val = :mailgateway ".
                "GROUP BY domains.id ".
                "ORDER BY domains.name ASC";
            $query2 = "SELECT domain_aliases.dom_id AS id, domain_aliases.id AS aid, domain_aliases.name ".
                "FROM domain_aliases ".
                "INNER JOIN dns_recs ON domain_aliases.dns_zone_id = dns_recs.dns_zone_id ".
                "WHERE dns_recs.type = 'MX' AND dns_recs.val = :mailgateway ".
                "ORDER BY domain_aliases.name ASC";
        } else { // custom mailgateway
            $query1 = "SELECT domains.id, domains.name, domains.destination ".
                "FROM domains ".
                "ORDER BY domains.name ASC";
        }

        // query master server
        $connection1 = $master_em->getConnection();
        $statement1 = $connection1->prepare($query1);
        if ($config['mode'] == 'ppa') {
            $statement1->bindValue('mailgateway', $config['mailgateway_server'].'.');
        }
        $statement1->execute();
        $records = $statement1->fetchAll();

        if ($config['mode'] == 'ppa') {
            $connection2 = $master_em->getConnection();
            $statement2 = $connection2->prepare($query2);
            $statement2->bindValue('mailgateway', $config['mailgateway_server'].'.');
            $statement2->execute();
            $records = array_merge($records, $statement2->fetchAll());
        }

        //echo "Query = ". $query ."<br>";

        if (count($records) > 0) {
            $msg .= "Count = ". count($records) ."<br><br>\n";
            
            foreach ($records as $record) {
                //var_dump($record);

                if (!array_key_exists('aid', $record)) {
                    $record['aid'] = 0;
                }

                $domain = $default_em->createQuery(
                    'SELECT d 
                    FROM jonasartsMailgatewaySyncBundle:Domain d
                    WHERE d.master_id = :id AND d.alias_id = :aid'
                    )
                    ->setParameter('id', $record['id'])
                    ->setParameter('aid', $record['aid'])
                    ->getOneOrNullResult();

                // load checksum
                // $domain->getChecksum();

                // load destination server
                if ($config['mode'] == 'ppa') {
                    $record['destination'] = $config['postfix_mailsever'];
                }

                if ($domain) { // domain record found -> update

                    $csr = hash('md5', $record['name'].":".$record['destination']); // remote cs
                    $csl = $domain->getChecksum(); // local cs

                    if ($csr != $csl) { // update domain.domainname & domain.destination & domain.last_sync

                        $msg .= "Update domain (" . $record['id'] . "/" . $record['aid'] . ") " . $record['name'] . " transport smtp:[".$record['destination']."]<br>\n";

                        // "UPDATE postfix_relay_domain SET domain = %s WHERE master_id = %d"
                        // "UPDATE postfix_transport SET domain = %s, destination = 'smtp:[%s]' WHERE master_id = %d"

                        $domain->setDomainname(trim($record['name']));
                        $domain->setMailservername(trim($record['destination']));
                        $domain->setLastSync(new \DateTime());
                        $default_em->persist($domain);
                        $default_em->flush();

                    } else { // only update domain.last_sync

                        $d = new \DateTime();
                        $msg .= "Update domain (" . $record['id'] . "/" . $record['aid'] . ") " . $record['name'] . " last sync " . $d->format('Y-m-d H:i:s') . "<br>\n";

                        // "UPDATE `sync` SET `lastrun` = %s WHERE `master_id` = %s"

                        $domain->setLastSync(new \DateTime());
                        $default_em->persist($domain);
                        $default_em->flush();
                    }
                } else { // no domain record -> insert

                    $msg .= "Insert domain (" . $record['id'] . "/" . $record['aid'] . ") " . $record['name'] . " transport smtp:[".$record['destination']."]<br>\n";

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
        if ($this->container->getParameter('mode') != 'custom') {
            die('Not operating in custom mode!');
        }

        $config = array();
        $config['mode'] = $this->container->getParameter('mode'); // custom / ppa
        $config['mailgateway_server'] = ''; // not needed
        $config['postfix_mailsever'] = ''; // not needed

        return $this->syncDomains($config);
    }

    private function syncPPADomains()
    {
        if ($this->container->getParameter('mode') != 'ppa') {
            die('Not operating in ppa mode!');
        }

        $config = array();
        $config['mode'] = $this->container->getParameter('mode'); // custom / ppa
        $config['mailgateway_server'] = $this->container->getParameter('mailgateway_server');
        $config['postfix_mailsever'] = $this->container->getParameter('postfix_mailserver');

        return $this->syncDomains($config);
    }

    /**
     * crontab: *|5 * * * * root curl -k -s -o /dev/null http://<domain>/sync-ppa
     * 
     * @Route("/sync-ppa", name="sync-ppa")
     * @Template("jonasartsMailgatewaySyncBundle:Default:sync.html.twig")
     */
    public function syncPPADomainsAction()
    {
        $request = $this->getRequest();

        $silent = true;
        $param = $request->query->get('silent');
        if ($param != null) {
            $silent = (bool)$param == true;
        }

        set_time_limit(0);

        $msg = $this->syncPPADomains();

        $msg = "PPA Domain Sync @ " . date('Y-m-d H:i:s') .
            " on " . $request->getHost() .
            " for MX " . $this->container->getParameter('mailgateway_server') .
            $msg;
        
        return array('msg' => $msg);
    }

    /**
     * crontab: *|5 * * * * root curl -k -s -o /dev/null http://<domain>/sync-custom
     * 
     * @Route("/sync-custom", name="sync-custom")
     * @Template("jonasartsMailgatewaySyncBundle:Default:sync.html.twig")
     */
    public function syncCustomDomainsAction()
    {
        $request = $this->getRequest();

        $silent = true;
        $param = $request->query->get('silent');
        if ($param != null) {
            $silent = (bool)$param == true;
        }

        set_time_limit(0);

        $msg = $this->syncCustomDomains();

        $msg = "Custom Domain Sync  @ " . date('Y-m-d H:i:s') .
            " on " . $request->getHost() .
            " for MX " . $this->container->getParameter('mailgateway_server') .
            $msg;
        
        return array('msg' => $msg);
    }

    /**
     * @Route("/show-expired", name="show_expired")
     * @Template("jonasartsMailgatewaySyncBundle:Default:sync.html.twig")
     */
    public function showExpiredDomainsAction()
    {
        $msg = $this->showExpiredDomains();

        return array('msg' => $msg);
    }

    /**
     * @Route("/delete-expired", name="delete_expired")
     * @Template("jonasartsMailgatewaySyncBundle:Default:sync.html.twig")
     */
    public function deleteExpiredDomainsAction()
    {
        $msg = $this->deleteExpiredDomains();

        return array('msg' => $msg);
    }
}
