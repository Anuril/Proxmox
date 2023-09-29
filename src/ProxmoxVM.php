<?php

/**
 * Proxmox module for FOSSBilling
 *
 * @author   FOSSBilling (https://www.fossbilling.org) & Anuril (https://github.com/anuril) 
 * @license  GNU General Public License version 3 (GPLv3)
 *
 * This software may contain code previously used in the BoxBilling project.
 * Copyright BoxBilling, Inc 2011-2021
 * Original Author: Scitch (https://github.com/scitch)
 *
 * This source file is subject to the GNU General Public License version 3 (GPLv3) that is bundled
 * with this source code in the file LICENSE. 
 * This Module has been written originally by Scitch (https://github.com/scitch) and has been forked from the original BoxBilling Module.
 * It has been rewritten extensively.
 */

namespace Box\Mod\Serviceproxmox;

/**
 * Proxmox module for FOSSBilling
 */
trait ProxmoxVM
{
	/* ################################################################################################### */
	/* #####################################  VM Management  ############################################ */
	/* ################################################################################################### */
	/**
	 * Suspend Proxmox VM
	 * @param $order
	 * @return boolean
	 */
	public function suspend($order, $model)
	{
		// Shutdown VM
		$this->vm_shutdown($order, $model);
		// TODO: Check that the VM was shutdown, otherwise send an email to the admin

		$model->updated_at = date('Y-m-d H:i:s');
		$this->di['db']->store($model);

		return true;
	}

	/**
	 * Unsuspend Proxmox VM
	 * @param $order
	 * @return boolean
	 */
	public function unsuspend($order, $model)
	{
		// Power on VM?
		$this->vm_start($order, $model);
		$model->updated_at = date('Y-m-d H:i:s');
		$this->di['db']->store($model);

		return true;
	}

	/**
	 * Cancel Proxmox VM
	 * @param $order
	 * @return boolean
	 */
	public function cancel($order, $model)
	{
		return $this->suspend($order, $model);
	}

	/**
	 * Uncancel Proxmox VM
	 * @param $order
	 * @return boolean
	 */
	public function uncancel($order, $model)
	{
		return $this->unsuspend($order, $model);
	}

	/**
	 * Delete Proxmox VM
	 * @param $order
	 * @return boolean
	 */
	public function delete($order, $model)
	{
		if (is_object($model)) {

			$product = $this->di['db']->load('product', $order->product_id);
			$product_config = json_decode($product->config, 1);

			// Retrieve associated server
			$server = $this->di['db']->findOne('service_proxmox_server', 'id=:id', array(':id' => $model->server_id));

			// Connect to YNH API
			$serveraccess = $this->find_access($server);
			$config = $this->di['mod_config']('Serviceproxmox');
			$proxmox = new PVE2_API($serveraccess, $server->root_user, $server->realm, $server->root_password, port: $server->port, tokenid: $server->tokenname, tokensecret: $server->tokenvalue,debug: $config['pmx_debug_logging']);

			if ($proxmox->login()) {
				$proxmox->post("/nodes/" . $model->node . "/" . $product_config['virt'] . "/" . $model->vmid . "/status/shutdown", array());
				$status = $proxmox->get("/nodes/" . $model->node . "/" . $product_config['virt'] . "/" . $model->vmid . "/status/current");

				// Wait until the server has been shut down if the server exists
				if (!empty($status)) {
					while ($status['status'] != 'stopped') {
						sleep(10);
						$proxmox->post("/nodes/" . $model->node . "/" . $product_config['virt'] . "/" . $model->vmid . "/status/shutdown", array());
						$status = $proxmox->get("/nodes/" . $model->node . "/" . $product_config['virt'] . "/" . $model->vmid . "/status/current");
					}
				} else {
					throw new \Box_Exception("VMID cannot be found");
				}
				if ($proxmox->delete("/nodes/" . $model->node . "/" . $product_config['virt'] . "/" . $model->vmid)) {
					// TODO: Check if that was the last VM for the client and delete the client user on Proxmox
					return true;
				} else {
					throw new \Box_Exception("VM not deleted");
				}
			} else {
				throw new \Box_Exception("Login to Proxmox Host failed");
			}
		}
	}

	/*
	*	VM status
	*
	*	TODO: Add more Information
	*/
	public function vm_info($order, $service)
	{
		$product = $this->di['db']->load('product', $order->product_id);
		$product_config = json_decode($product->config, 1);
		$client = $this->di['db']->load('client', $order->client_id);

		// Retrieve associated server
		$server = $this->di['db']->findOne('service_proxmox_server', 'id=:id', array(':id' => $service->server_id));
		$clientuser = $this->di['db']->findOne('service_proxmox_users', 'server_id = ? and client_id = ?', array($server->id, $client->id));

		// Test if login
		$serveraccess = $this->find_access($server);
		$proxmox = new PVE2_API($serveraccess, $server->root_user, $server->realm, $server->root_password, port: $server->port, tokenid: $clientuser->admin_tokenname, tokensecret: $clientuser->admin_tokenvalue,debug: $config['pmx_debug_logging']);
		if ($proxmox->get_version()) {
			$status = $proxmox->get("/nodes/" . $server->name . "/" . $product_config['virt'] . "/" . $service->vmid . "/status/current");
			// VM monitoring?

			$output = array(
				'status'	=> $status['status']
			);
			return $output;
		} else {
			throw new \Box_Exception("Login to Proxmox Host failed.");
		}
	}

	/*
		Cold Reboot VM
	*/
	public function vm_reboot($order, $service)
	{
		$product = $this->di['db']->load('product', $order->product_id);
		$product_config = json_decode($product->config, 1);
		$client = $this->di['db']->load('client', $order->client_id);

		// Retrieve associated server
		$server = $this->di['db']->findOne('service_proxmox_server', 'id=:id', array(':id' => $service->server_id));
		$clientuser = $this->di['db']->findOne('service_proxmox_users', 'server_id = ? and client_id = ?', array($server->id, $client->id));

		// Test if login
		$serveraccess = $this->find_access($server);
		$proxmox = new PVE2_API($serveraccess, $server->root_user, $server->realm, $server->root_password, port: $server->port, tokenid: $clientuser->admin_tokenname, tokensecret: $clientuser->admin_tokenvalue,debug: $config['pmx_debug_logging']);
		if ($proxmox->login()) {
			$proxmox->post("/nodes/" . $server->name . "/" . $product_config['virt'] . "/" . $service->vmid . "/status/shutdown", array());
			$status = $proxmox->get("/nodes/" . $server->name . "/" . $product_config['virt'] . "/" . $service->vmid . "/status/current");

			// Wait until the VM has been shut down if the VM exists
			if (!empty($status)) {
				while ($status['status'] != 'stopped') {
					sleep(10);
					$proxmox->post("/nodes/" . $server->name . "/" . $product_config['virt'] . "/" . $service->vmid . "/status/shutdown", array());
					$status = $proxmox->get("/nodes/" . $server->name . "/" . $product_config['virt'] . "/" . $service->vmid . "/status/current");
				}
			}
			// Restart
			if ($proxmox->post("/nodes/" . $server->name . "/" . $product_config['virt'] . "/" . $service->vmid . "/status/start", array())) {
				sleep(10);
				$status = $proxmox->get("/nodes/" . $server->name . "/" . $product_config['virt'] . "/" . $service->vmid . "/status/current");
				while ($status['status'] != 'running') {
					$proxmox->post("/nodes/" . $server->name . "/" . $product_config['virt'] . "/" . $service->vmid . "/status/start", array());
					sleep(10);
					$status = $proxmox->get("/nodes/" . $server->name . "/" . $product_config['virt'] . "/" . $service->vmid . "/status/current");
					// Starting twice => error...
				}
				return true;
			} else {
				throw new \Box_Exception("Reboot failed");
			}
		} else {
			throw new \Box_Exception("Login to Proxmox Host failed.");
		}
	}

	/*
		Start VM
	*/
	public function vm_start($order, $service)
	{
		$product = $this->di['db']->load('product', $order->product_id);
		$product_config = json_decode($product->config, 1);
		$client = $this->di['db']->load('client', $order->client_id);
		// Retrieve associated server
		// Retrieve associated server
		$server = $this->di['db']->findOne('service_proxmox_server', 'id=:id', array(':id' => $service->server_id));
		$clientuser = $this->di['db']->findOne('service_proxmox_users', 'server_id = ? and client_id = ?', array($server->id, $client->id));

		// Test if login
		$serveraccess = $this->find_access($server);
		$proxmox = new PVE2_API($serveraccess, $server->root_user, $server->realm, $server->root_password, port: $server->port, tokenid: $clientuser->admin_tokenname, tokensecret: $clientuser->admin_tokenvalue,debug: $config['pmx_debug_logging']);
		if ($proxmox->login()) {
			$proxmox->post("/nodes/" . $server->name . "/" . $product_config['virt'] . "/" . $service->vmid . "/status/start", array());
			return true;
		} else {
			throw new \Box_Exception("Login to Proxmox Host failed.");
		}
	}

	/*
		Shutdown VM
	*/
	public function vm_shutdown($order, $service)
	{
		$product = $this->di['db']->load('product', $order->product_id);
		$product_config = json_decode($product->config, 1);
		$client = $this->di['db']->load('client', $order->client_id);
		// Retrieve associated server
		$server = $this->di['db']->findOne('service_proxmox_server', 'id=:id', array(':id' => $service->server_id));

		// Test if login
		// find service access for server
		$clientuser = $this->di['db']->findOne('service_proxmox_users', 'server_id = ? and client_id = ?', array($server->id, $client->id));
		//echo "D: ".var_dump($order);
		$serveraccess = $this->find_access($server);
		$proxmox = new PVE2_API($serveraccess, $server->root_user, $server->realm, $server->root_password, port: $server->port, tokenid: $clientuser->admin_tokenname, tokensecret: $clientuser->admin_tokenvalue,debug: $config['pmx_debug_logging']);
		if ($proxmox->login()) {
			$settings = array(
				'forceStop' 	=> true
			);

			$proxmox->post("/nodes/" . $server->name . "/" . $product_config['virt'] . "/" . $service->vmid . "/status/shutdown", $settings);
			return true;
		} else {
			throw new \Box_Exception("Login to Proxmox Host failed.");
		}
	}


	/*
		VM iframe for Web CLI
	*/
	public function vm_cli($order, $service)
	{

		$product = $this->di['db']->load('product', $order->product_id);
		$product_config = json_decode($product->config, 1);
		$client  = $this->di['db']->load('client', $order->client_id);

		// Retrieve associated server
		$server = $this->di['db']->findOne('service_proxmox_server', 'id=:id', array(':id' => $service->server_id));
		$clientuser = $this->di['db']->findOne('service_proxmox_users', 'server_id = ? and client_id = ?', array($server->id, $client->id));

		// Find access route
		$serveraccess = $this->find_access($server);

		// Setup console type
		$console = ($product_config['virt'] == 'qemu') ? 'kvm' : 'shell';

		// The user enters the password to see the iframe: TBD
		//$password = 'test';
		//$proxmox = new PVE2_API($serveraccess, $client->id, $server->name, $password);

		$proxmox = new PVE2_API($serveraccess, $server->root_user, $server->realm, $server->root_password, port: $server->port, tokenid: $clientuser->view_tokenname, tokensecret: $clientuser->view_tokenvalue,debug: $config['pmx_debug_logging']);

		if ($proxmox->login()) {
			// Get VNC Web proxy ticket by calling /nodes/{node}/{type}/{vmid}/vncproxy
			$vncproxy_response = $proxmox->post("/nodes/" . $server->name . "/" . $product_config['virt'] . "/" . $service->vmid . "/vncproxy",	array('node' => $server->name, 'vmid' => $service->vmid));
			$vncproxy_port = $vncproxy_response['data']['port'];
			$vncproxy_ticket = $vncproxy_response['data']['ticket'];
			// open a vnc web socket
			// we'll do it ourselves until the upstream API supports it
			$put_post_http_headers[] = "Authorization: PVEAPIToken={$clientuser->view_tokenname}={$clientuser->view_tokenvalue}";
			// add the vncticket to the post_http_headers
			$put_post_http_headers[] = "vncticket: {$vncproxy_ticket}";
			// add the port to the post_http_headers
			$put_post_http_headers[] = "port: {$vncproxy_port}";
			// add the host to the post_http_headers
			$put_post_http_headers[] = "host: {$serveraccess}";
			// add the vmid to the post_http_headers
			$put_post_http_headers[] = "vmid: {$service->vmid}";
			// open websocket connection and display the console
			/* 			curl_setopt($prox_ch, CURLOPT_URL, "https://{$serveraccess}:8006/api2/json/nodes/{$server->name}/{$product_config['virt']}/{$service->vmid}/vncwebsocket");
			curl_setopt($prox_ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($prox_ch, CURLOPT_POSTFIELDS, $login_postfields_string);
			curl_setopt($prox_ch, CURLOPT_SSL_VERIFYPEER, $this->verify_ssl);
			curl_setopt($prox_ch, CURLOPT_SSL_VERIFYHOST, $this->verify_ssl);
			*/
			// return array of vncport & ticket
			return array('port' => $vncproxy_port, 'ticket' => $vncproxy_ticket);
		} else {
			throw new \Box_Exception("Login to Proxmox VM failed.");
		}
	}
}
