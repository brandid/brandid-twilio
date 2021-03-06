<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class brandidhelpline extends CI_Controller
{
	protected $caller_id;
	protected $support_office;
	protected $support_mobile;
	protected $voice;
	protected $no_voicemail_timeout;
	protected $agent_timeout;
	protected $agent_attempts;
	protected $office_hours_start;
	protected $office_hours_end;
	protected $hold_music;
	protected $log;
	protected $logfile;

	function __construct()
	{
		parent::__construct();
		$this->load->config('twilio');
		$this->load->library(array('twilio', 'session'));
		$this->load->helper(array('url', 'file'));

		$this->caller_id = $this->config->item('caller_id');
		$this->support_office = $this->config->item('support_office');
		$this->support_mobile = $this->config->item('support_mobile');
		$this->voice = array('voice' => $this->config->item('voice_gender'), 'language' => $this->config->item('voice_language'));
		$this->no_voicemail_timeout = $this->config->item('no_voicemail_timeout');
		$this->agent_timeout = $this->config->item('agent_timeout');
		$this->agent_attempts = $this->config->item('agent_attempts');
		$this->office_hours_start = $this->config->item('office_hours_start');
		$this->office_hours_end = $this->config->item('office_hours_end');
		$this->hold_music = $this->config->item('hold_music');
		$this->log = $this->config->item('twilio_log');
		$this->logfile = $this->config->item('twilio_logfile');
		date_default_timezone_set('Europe/London');
	}

	/**
	 * Accept incoming support calls. Callers get a quick message and then join a conference (unique to them) to which a support agent is later joined.
	 */
	function index()
	{
		$hour = date('H');
		$day = date('w');
		$response = $this->twilio->addResponse();

		// Reject out of hours calls
		if ($hour < $this->office_hours_start || $hour > $this->office_hours_end || $day == 0 || $day == 6)
		{
			$response->addSay("Thank you for calling branded customer support. I'm afraid we're currently closed. Please call back " . $this->office_hours_start . 'am to ' . ($this->office_hours_end - 12) . 'pm Monday to Friday.', $this->voice);
			$this->_writelog("Out of hours call.");
		}
		else
		{
			// Call agent and put caller on hold
			try
			{
				// Use this as conference ID
				if (isset($_REQUEST['CallSid']))
				{
					$conference_sid = $_REQUEST['CallSid'];
					$call_from = isset($_REQUEST['From']) ? $_REQUEST['From'] : $this->caller_id;
					
					$this->session->set_userdata('Accepted', 'false');
					$this->twilio->call($call_from, $this->support_office, array('Url' => site_url('brandidhelpline/callagent/?ConferenceSid=' . urlencode($conference_sid)), 'StatusCallback' => site_url('brandidhelpline/forwardcall/?ConferenceSid=' . urlencode($conference_sid) . '&CallFrom=' . urlencode($call_from))));

					$response->addSay('Thank you for calling branded customer support. Please wait while we connect you to a support agent.', $this->voice);
					$dial = $response->addDial();
					$dial->addConference($conference_sid, array('waitUrl' => $this->hold_music, 'beep' => 'false'));
					$this->_writelog("$conference_sid Connecting call... (index)");
				}
				else
				{
					$response->addSay("Thank you for calling branded customer support. I'm sorry, but there has been a problem connecting your call. Please try again later.", $this->voice);
					$this->_writelog("ERROR No CallSid (index).");
				}
			}
			catch (Exception $e)
			{
				$response->addSay("Thank you for calling branded customer support. I'm sorry, but there has been a problem connecting your call. Please try again later.", $this->voice);
				$this->_writelog('ERROR exception "' . $e->getMessage() . '" (index).');
			}			
		}

		$view['response'] = $response;
		$this->load->view('response_view', $view);
	}

	/**
	 * If no answer on main support line, try mobile.
	 */	
	function forwardcall()
	{
		$response = $this->twilio->addResponse();
		
		if (isset($_REQUEST['ConferenceSid']) && isset($_REQUEST['CallStatus']))
		{
			$conference_sid = urldecode($_REQUEST['ConferenceSid']);
			$call_status = $_REQUEST['CallStatus'];
			$call_from = isset($_REQUEST['CallFrom']) ? urldecode($_REQUEST['CallFrom']) : $this->caller_id;

			// If the call hasn't been answered, forward it. 
			if ($call_status == 'no-answer' || $call_status == 'failed' || $call_status == 'busy' || $this->session->userdata('Accepted') !== 'true')
			{
				$this->twilio->call($call_from, $this->support_mobile, array('Url' => site_url('brandidhelpline/callagent/?ConferenceSid=' . urlencode($conference_sid)), 'StatusCallback' => site_url('brandidhelpline/completedcall/?ConferenceSid=' . urlencode($conference_sid)), 'Timeout' => $this->no_voicemail_timeout));
				$this->_writelog("$conference_sid Forwarding call with status $call_status (forwardcall)...");
			}
			else
			{
				// Sometimes Twilio doesn't connect the call and leaves the caller hanging with hold music. Try to complete call just in case. (Doesn't play the failed call message as hangs up, but want to avoid redialling support mobile if leave URL unchanged.)
				$this->twilio->modifyCall($conference_sid, site_url('brandidhelpline/failedcall/'), array('Status' => 'completed'));
				$this->_writelog("$conference_sid Completed call with status $call_status (forwardcall).");
			}
		}
		else
		{
			$this->_writelog("ERROR No CallSid or CallStatus (forwardcall).");
		}
	}

	/**
	 * Handle completed and failed calls. Failed calls get a 'sorry' message.
	 */	
	function completedcall()
	{
		$response = $this->twilio->addResponse();

		if (isset($_REQUEST['ConferenceSid']) && isset($_REQUEST['CallStatus']))
		{
			$conference_sid = urldecode($_REQUEST['ConferenceSid']);
			$call_status = $_REQUEST['CallStatus'];

			// If the call hasn't been answered, interrupt conference.
			if ($call_status == 'no-answer' || $call_status == 'failed' || $call_status == 'busy' || $this->session->userdata('Accepted') !== 'true')
			{
				// Remove incoming call from conference
				$this->twilio->modifyCall($conference_sid, site_url('brandidhelpline/failedcall/'));
				$this->_writelog("$conference_sid Failed to connect call status $call_status (completedcall).");
			}
			else
			{
				// Sometimes Twilio doesn't connect the call and leaves the caller hanging with hold music. Try to complete call just in case.
				$this->twilio->modifyCall($conference_sid, site_url('brandidhelpline/failedcall/'), array('Status' => 'completed'));
				$this->_writelog("$conference_sid Completed call status $call_status (completedcall).");
			}
		}
		else
		{
			$this->_writelog("ERROR No CallSid or CallStatus (forwardcall).");
		}

		$view['response'] = $response;
		$this->load->view('response_view', $view);
	}

	/**
	 * If can't get through at all, play a sorry message and hang up.
	 */	
	function failedcall()
	{
		$response = $this->twilio->addResponse();

		$response->addSay("I'm sorry, all of our agents are busy with other calls. Please call back " . $this->office_hours_start . 'am to ' . ($this->office_hours_end - 12) . 'pm Monday to Friday.', $this->voice);
		$this->_writelog("Failed call (failedcall).");

		$view['response'] = $response;
		$this->load->view('response_view', $view);
	}

	/**
	 * Join agent to the incoming call's conference.
	 * 
	 * Use session cookies here to store attempts and whether call accepted (needed as can't change query parameters on StatusCallback function).
	 * Don't use them generally as Twilio client's session cookies are per call, so are not transferred between incoming and agent calls. Gah. 
	 * (NB Are also lost when callagent is called again with mobile number.)
	 */	
	function callagent()
	{
		$response = $this->twilio->addResponse();

		if (isset($_REQUEST['ConferenceSid']) && isset($_REQUEST['CallStatus']))
		{
			$conference_sid = $_REQUEST['ConferenceSid'];
			$call_status = $_REQUEST['CallStatus'];
			$attempt = $this->session->userdata('Attempt') ? $this->session->userdata('Attempt') : 0;

			// If been here before without a reply but Twilio has followed the redirect anyway. 
			if ($call_status == 'no-answer' || $call_status == 'failed' || $call_status == 'busy')
			{
				$this->_writelog("$conference_sid Agent refused to accept call with status $call_status on attempt $attempt (callagent).");
			}
			// Agent pressed key to answer?
			elseif (isset($_REQUEST['Digits']))
			{
				// Join incoming caller's conference
				$dial = $response->addDial();
				$dial->addConference($conference_sid, array('beep' => 'false', 'endConferenceOnExit' => 'true'));
				$this->session->set_userdata('Accepted', 'true');
				$this->_writelog("$conference_sid Connected agent to call on attempt $attempt (callagent).");
			}
			else
			{
				$gather = $response->addGather(array('timeout' => $this->agent_timeout, 'numDigits' => 1));
				$gather->addSay('This is a call from branded. Press any key to answer.', $this->voice);
				$this->_writelog("$conference_sid Waiting for agent to accept call attempt $attempt (callagent).");

				// Being ignored? Try again after timeout.
				if ($attempt < $this->agent_attempts)
				{
					$this->session->set_userdata('Attempt', $attempt + 1);					
					$dial = $response->addRedirect(site_url('brandidhelpline/callagent/?ConferenceSid=' . urlencode($conference_sid)));
				}
			}
		}
		else
		{
			$response->addSay("This is a call from branded. I'm afraid something's gone wrong and I can't connect you to the caller. Sorry about that.", $this->voice);
			$this->_writelog("ERROR No CallSid (callagent).");
		}

		$view['response'] = $response;
		$this->load->view('response_view', $view);
	}

	/**
	 * What's the time, Mr. Wolf? Check the webserver time if the out of hours message is misbehaving.
	 */	
	function hour()
	{
		echo 'The hour is ' . date('H') . '.';
	}

	/**
	 * Write call status to a log.
	 *
	 * @param string $data Stuff to log
	 */	
	protected function _writelog($data)
	{
		if ($this->log)
		{
			write_file('./application/logs/' . $this->logfile, date('D d M Y H:i:s') . ' ' . $data . "\r\n", 'a');
		}
	}
}
