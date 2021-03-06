<?php

class NetworkDevice {
    public $device;
    public $ips;
    public $bridge;
    public $is_span;
    public $dhcp;
    public $jail;
    public $ipv6;

    public static function Load($jail) {
        $result = db_query('SELECT * FROM {jailadmin_epairs} WHERE jail = :jail', array(':jail' => $jail->name));

        $devices = array();

        while ($record = $result->fetchAssoc())
            $devices[] = NetworkDevice::LoadFromRecord($jail, $record);

        return $devices;
    }

    public static function LoadByDeviceName($jail, $name) {
        $result = db_query('SELECT * FROM {jailadmin_epairs} WHERE device = :device', array(':device' => $name));

        $record = $result->fetchAssoc();
        return NetworkDevice::LoadFromRecord($jail, $record);
    }

    public function IsOnline() {
        $o = exec("/usr/local/bin/sudo /sbin/ifconfig {$this->device}a 2>&1 | grep -v \"does not exist\"");
        return strlen($o) > 0;
    }

    public function BringHostOnline() {
        if ($this->IsOnline())
            return TRUE;

        if ($this->bridge->BringOnline() == FALSE)
            return FALSE;

        exec("/usr/local/bin/sudo /sbin/ifconfig {$this->device} create");
        if ($this->is_span) {
            exec("/usr/local/bin/sudo /sbin/ifconfig {$this->bridge->device} span {$this->device}a");
        } else {
            exec("/usr/local/bin/sudo /sbin/ifconfig {$this->bridge->device} addm {$this->device}a");
        }

        exec("/usr/local/bin/sudo /sbin/ifconfig {$this->device}a up");

        return TRUE;
    }

    public function BringGuestOnline() {
        if ($this->jail->IsOnline() == FALSE)
            return FALSE;

        if ($this->IsOnline() == FALSE)
            if ($this->BringHostOnline() == FALSE)
                return FALSE;

        exec("/usr/local/bin/sudo /sbin/ifconfig {$this->device}b vnet \"{$this->jail->name}\"");
        foreach ($this->ips as $ip) {
            $inet = (strstr($ip, ':') === FALSE) ? 'inet' : 'inet6';
            exec("/usr/local/bin/sudo /usr/sbin/jexec \"{$this->jail->name}\" ifconfig {$this->device}b {$inet} \"{$ip}\" alias");
        }

        exec("/usr/local/bin/sudo /usr/sbin/jexec \"{$this->jail->name}\" /sbin/ifconfig {$this->device}b up");

        if ($this->dhcp)
            exec("/usr/local/bin/sudo /usr/sbin/jexec \"{$this->jail->name}\" /sbin/dhclient {$this->device}b > /dev/null 2>&1 &");

        return TRUE;
    }

    public function BringOffline() {
        if ($this->IsOnline() == FALSE)
            return TRUE;

        exec("/usr/local/bin/sudo /sbin/ifconfig {$this->device}a destroy");

        return TRUE;
    }

    protected static function LoadFromRecord($jail, $record=array()) {
        if (count($record) == 0)
            return FALSE;

        $net_device = new NetworkDevice;
        $net_device->device = $record['device'];
        $net_device->is_span = ($record['is_span'] == 1) ? TRUE : FALSE;
        $net_device->dhcp = ($record['dhcp'] == 1) ? TRUE : FALSE;
        $net_device->bridge = Network::Load($record['bridge']);
        $net_device->jail = $jail;
        $net_device->ipv6 = false;

        $net_device->ips = array();
        $ip_records = db_select('jailadmin_epair_aliases', 'jea')
            ->fields('jea', array('ip'))
            ->condition('device', $net_device->device)
            ->execute();

        foreach ($ip_records as $ip_record) {
            $net_device->ips[] = $ip_record->ip;
            if (strstr($ip_record->ip, ":") !== FALSE)
                $net_device->ipv6 = true;
        }

        return $net_device;
    }

    public static function IsDeviceAvailable($device) {
        $result = db_query('SELECT device FROM {jailadmin_epairs}');

        foreach ($result as $record)
            if (!strcmp($record->device, $device))
                return FALSE;

        return TRUE;
    }

    public static function IsIPAvailable($ip) {
        $result = db_select('jailadmin_epair_aliases', 'jea')
            ->fields('jea', array('ip'))
            ->condition('jea.ip', $ip)
            ->execute();

        foreach ($result as $record)
            if ($record->ip == $ip)
                return false;

        $result = db_select('jailadmin_bridge_aliases', 'jba')
            ->fields('jba', array('ip'))
            ->condition('jba.ip', $ip)
            ->execute();

        foreach ($result as $record)
            if ($record->ip == $ip)
                return false;

        return true;
    }

    public static function NextAvailableDevice() {
        $result = db_query('SELECT device FROM {jailadmin_epairs}');

        $id = 0;
        foreach ($result as $record) {
            $i = substr($record->device, strlen("epair"));
            if (intval($i) > $id)
                $id = intval($i);
        }

        for (++$id; ; $id++)
            if (NetworkDevice::IsDeviceAvailable("epair{$id}"))
                break;

        return $id;
    }

    public function Create() {
        if (NetworkDevice::IsDeviceAvailable($this->device) == FALSE)
            return FALSE;

        db_insert('jailadmin_epairs')
            ->fields(array(
                'jail' => $this->jail->name,
                'device' => $this->device,
                'bridge' => $this->bridge->name,
                'is_span' => ($this->is_span) ? 1 : 0,
                'dhcp' => ($this->dhcp) ? 1 : 0,
            ))->execute();

        return TRUE;
    }

    public function Delete() {
        db_delete('jailadmin_epairs')
            ->condition('device', $this->device)
            ->condition('jail', $this->jail->name)
            ->execute();

        db_delete('jailadmin_epair_aliases')
            ->condition('device', $this->device)
            ->execute();
    }
}
