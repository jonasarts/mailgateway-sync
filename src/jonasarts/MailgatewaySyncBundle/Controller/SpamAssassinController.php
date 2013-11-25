<?php

namespace jonasarts\MailgatewaySyncBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

use jonasarts\MailgatewaySyncBundle\Entity\Domain;

class SpamAssassinController extends Controller
{
    private function syncSpamAssassin($config, $force = false)
    {
        if ($this->container->getParameter('mode') != 'custom') {
            die('Not operating in custom mode!');
        }

        // filesystem checks
        if ($this->container->getParameter('debug')) {
            if (!is_dir(dirname($config['files']['spamassassin_rules']))) {
                die("Folder ".dirname($config['files']['spamassassin_rules'])." does not exist!");
            }
            if (!is_writable(dirname($config['files']['spamassassin_rules']))) { // check write permission
                die("Folder ".dirname($config['files']['spamassassin_rules'])." is not writable!");
            }
            if (!file_exists($config['files']['spamassassin_rules'])) {
                touch($config['files']['spamassassin_rules']);
            } elseif (!is_writable($config['files']['spamassassin_rules'])) { // check write permission
                die("Can not write file ".$config['files']['spamassassin_rules']);
            }
        }

        $msg = "<h1>Sync Spamassassin</h1>\n";

        $master_em = $this->get('doctrine')->getManager('master');

        // blacklist
        $blacklist = "# Blacklists\n\n"; // spamassassin blacklist config
        
        $query = "SELECT `b`.`list` ".
        "FROM `spamassassin_blacklist` AS `b` ".
        "ORDER BY `b`.`list` ASC";

        // query master server
        $connection = $master_em->getConnection();
        $statement = $connection->prepare($query);
        $result = $statement->execute();

        if ($result) {
            $records = $statement->fetchAll();
            foreach ($records as $record) {
                $blacklist .= "blacklist_from ".$record['list']."\n";
            }
        }

        // whitelist
        $whitelist = "# Whitelists\n\n"; // spamassassin whitelist config

        $query = "SELECT `w`.`list` ".
        "FROM `spamassassin_whitelist` AS `w` ".
        "ORDER BY `w`.`list` ASC";

        // query master server
        $connection = $master_em->getConnection();
        $statement = $connection->prepare($query);
        $result = $statement->execute();

        if ($result) {
            $records = $statement->fetchAll();
            foreach ($records as $record) {
                $whitelist .= "whitelist_from ".$record['list']."\n";
            }
        }

        // rules
        $rules = "# Rules\n\n"; // spamassassin rules config
        $scores = "# Scores\n\n"; // spamassassin rules config

        // rulename, header, body, score, description
        $query = "SELECT `r`.* ".
        "FROM `spamassassin_rules` AS `r` ".
        "ORDER BY `r`.`rulename` ASC";

        // query master server
        $connection = $master_em->getConnection();
        $statement = $connection->prepare($query);
        $result = $statement->execute();

        if ($result) {
            $records = $statement->fetchAll();
            foreach ($records as $record) {
                $rule = "";

                if (trim($record['header']) != "") {
                    $rule .= "header ".$record['rulename']." ".$record['header']."\n";
                }
                if (trim($record['body']) != "") {
                    $rule .= "body ".$record['rulename']." ".$record['body']."\n";
                }

                if (trim($rule) != "") { // header || body rule
                    $rule .= "score ".$record['rulename']." ".$record['score']."\n";
                    if (trim($record['description']) != "") {
                        $rule .= "describe ".$record['rulename']." ".$record['description']."\n";
                    }
                    $rules .= $rule."\n";
                } else { // generic rule
                    $score = "score ".$record['rulename']." ".$record['score']."\n";

                    $scores .= $score; // ."\n";
                }
            }
        }

        // generate spamassassin config file content
        $data = "# Custom Mailgatway SpamAssassin Configuration\n".
            "#\n\n".
            $blacklist."\n".
            $whitelist."\n".
            $rules. // "\n".
            $scores;
    
        // get checksums
        $rm = $this->get('registry_manager'); // get registy service
        $csr = hash('md5', $data); // remote checksum
        $csl = $rm->SystemRead('spamassassin', 'checksum', 'str'); // local checksum

        // write spamassassin config file
        if ($csl) { // update
            if (($csr != $csl) || $force) { // update
                file_put_contents($config['files']['spamassassin_rules'], $data);

                $rm->SystemWrite('spamassassin', 'checksum', 'str', $csr);

                $msg .= "SpamAssassin rules updated<br>\n";
            } else {
                $msg .= "SpamAssassin rules already up2date<br>\n";
            }
        } else { // insert
            file_put_contents($config['files']['spamassassin_rules'], $data);

            $rm->SystemWrite('spamassassin', 'checksum', 'str', $csr);

            $msg .= "SpamAssassin rules created<br>\n";
        }

        return $msg;
    }

    /**
     * crontab: *|5 * * * * root curl -k -s -o /dev/null http://<domain>/sync-sa
     * 
     * @Route("/sync-sa", name="sync_sa")
     * @Template("jonasartsMailgatewaySyncBundle:Default:sync.html.twig")
     */
    public function syncSpamassassinAction()
    {
        $chroot = "";
        if ($this->container->hasParameter('chroot')) {
            $chroot = $this->container->getParameter('chroot');
        }

        $config = array();
        $config['files'] = array();
        $config['files']['spamassassin_rules'] = $chroot."/etc/mail/spamassassin/webfinity_rules.cf";

        $request = $this->getRequest();
        
        $silent = true;
        $param = $request->query->get('silent');
        if ($param != null) {
            $silent = (bool)$param == true;
        }

        $force = $request->query->get('force') == true;

        $msg = $this->syncSpamAssassin($config, $force);

        if ($silent) {
            $msg = "SpamAssassin Sync @ " . date('Y-m-d H:i:s') . " on " . $request->getHost();
        }

        return array('msg' => $msg);
    }
}
