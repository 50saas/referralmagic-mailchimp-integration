<?php

require 'vendor/autoload.php';

use \DrewM\MailChimp\MailChimp;

// CONFIGURATION - Start
$rm_api_key = getenv('REFERRALMAGIC_APIKEY');
$mc_api_key = getenv('MAILCHIMP_APIKEY');
$mc_target_listid = getenv('MAILCHIMP_TARGET_LISTID');
$rm_campaign_id = getenv('REFERRALMAGIC_TARGET_CAMPAIGNID');
// CONFIGURATION - End

$mc = new MailChimp($mc_api_key);

// Step 1: Retrieve the merge field ID of referral code merge field
$merge_field = mc_get_rm_refcode_fieldid($mc, $mc_target_listid);

// Step 2: Create ReferralMagic referral code merge field
if (is_bool($merge_field) == true && $merge_field == false) {
    mc_create_rm_refcode_field($mc, $mc_target_listid);
    $merge_field = mc_get_rm_refcode_fieldid($mc, $mc_target_listid);
}

// Step 3: Retrieve all subscribers from Mailchimp
$members = mc_get_members($mc, $mc_target_listid);

// Step 4: Generate ref code for each member
if (count($members) > 0) {
    foreach ($members as $member_id => $each_member) {
        $rm_refcode = rm_generate_refcode($rm_campaign_id, $each_member['email'], $rm_api_key);

        mc_save_member_refcode($mc, $mc_target_listid, $each_member['email'], $rm_refcode);
    }
}

exit;


function rm_generate_refcode($rm_campaign_id, $rm_person_email, $rm_api_key)
{
    $client = new GuzzleHttp\Client();

    try {
        $res = $client->request('POST', 'https://api.referralmagic.co/referral-codes', array(
            'body' => json_encode(array(
                'campaign_id' => $rm_campaign_id,
                'person_email' => $rm_person_email
            )),
            'headers' => array(
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $rm_api_key,
            )
        ));
        $response = json_decode($res->getBody(), true);

        return $response['code'];
    } catch (GuzzleHttp\Exception\RequestException $e) {
        print $e->getMessage();
        exit;
    }
}

function mc_save_member_refcode($mc, $list_id, $email, $rm_ref_code)
{
    $result = $mc->patch('lists/' . $list_id . '/members/' . md5($email), array(
        'merge_fields' => array(
            'RM_REFCODE' => $rm_ref_code
        )
    ));
}

function mc_get_members($mc, $list_id)
{
    $rpp = 1000;
    $start = 0;
    $members = array();

    while (true) {
        $result = $mc->get('lists/' . $list_id . '/members?count=' . $rpp . '&offset=' . $start);

        if (isset($result['members']) == true && count($result['members']) > 0) {
            foreach ($result['members'] as $index => $each_member) {
                if (isset($each_member['merge_fields']['RM_REFCODE']) == true && $each_member['merge_fields']['RM_REFCODE'] != '') {
                    continue;
                }
                $members[$each_member['id']] = array('member_id' => $each_member['id'], 'email' => $each_member['email_address']);
            }

            $start += $rpp;
        } else {
            break;
        }
    }

    return $members;
}

function mc_get_rm_refcode_fieldid($mc, $list_id)
{
    $result = $mc->get('/lists/' . $list_id . '/merge-fields');

    $rm_refcode_field = null;
    if (isset($result['merge_fields']) == true && count($result['merge_fields']) > 0) {
        foreach ($result['merge_fields'] as $index => $each_field) {
            if ($each_field['tag'] == 'RM_REFCODE') {
                $rm_refcode_field = $each_field;
            }
        }
    }


    if (is_null($rm_refcode_field) == true) {
        return false;
    } else {
        return $rm_refcode_field;
    }
}

function mc_create_rm_refcode_field($mc, $list_id)
{
    $result = $mc->post('/lists/' . $list_id . '/merge-fields', array(
        'tag' => 'RM_REFCODE',
        'name' => 'ReferralMagic Referral Code',
        'type' => 'text',
        'required' => false,
        'public' => false,
        'help_text' => 'ReferralMagic referral code. This field is auto-populated by ReferralMagic / Mailchimp integration script.'
    ));
}