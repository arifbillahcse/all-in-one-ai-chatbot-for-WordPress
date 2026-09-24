{*
 * WHMCS AI Chat - Customer Identity Token Generator
 *
 * Generates a signed identity token for logged-in customers.
 * This allows the chatbot to show account-specific information.
 *
 * SECURITY: Do NOT commit IDENTITY_BRIDGE_SECRET to git.
 * Store it in .env on your WHMCS server only.
 *}

{function name="getAiChatToken"}
    {if $loggedin && $clientsdetails.id}
        {assign var="endpoint" value="https://chat.hostorio.com/api/identity/token"}
        {assign var="bridgeSecret" value="REPLACE_WITH_IDENTITY_BRIDGE_SECRET"}
        {assign var="customerId" value=$clientsdetails.id}

        {php}
            // Server-to-server call to mint signed identity token
            // This is called from the WHMCS backend, never from the browser

            $endpoint = $this->get_template_vars('endpoint');
            $bridgeSecret = $this->get_template_vars('bridgeSecret');
            $customerId = $this->get_template_vars('customerId');

            // Validate secret was replaced
            if (strpos($bridgeSecret, 'REPLACE_WITH_') !== false) {
                error_log('AI Chat: IDENTITY_BRIDGE_SECRET not configured');
                $this->assign('aiChatToken', '');
                return;
            }

            // Call chatbot's token endpoint
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $endpoint,
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-Bridge-Secret: ' . $bridgeSecret,
                ],
                CURLOPT_POSTFIELDS => json_encode([
                    'customer_id' => (int) $customerId,
                ]),
            ]);

            $response = @curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            $token = null;
            if ($httpCode === 200 && $response) {
                $data = json_decode($response, true);
                if ($data['ok'] ?? false) {
                    $token = $data['token'] ?? null;
                }
            } else {
                error_log('AI Chat token generation failed: HTTP ' . $httpCode . ' - ' . $error);
            }

            $this->assign('aiChatToken', $token ?: '');
        {/php}

        {return $aiChatToken}
    {/if}

    {return ''}
{/function}

{* Generate token and store in Smarty variable *}
{getAiChatToken assign="aiToken"}
