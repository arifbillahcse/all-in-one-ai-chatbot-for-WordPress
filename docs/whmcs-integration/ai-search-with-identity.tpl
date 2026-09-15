{*
 * WHMCS AI Chat Search Box - With Customer Identity
 *
 * Updated version of ai-search.tpl that includes customer identity token.
 * Shows account-specific information when customer is logged in.
 *}

{* Include the identity token generator *}
{include file="includes/ai-identity-helper.tpl"}

<section class="ho-ai-search"
         data-endpoint="{$WEB_ROOT|cat:'/api/chat'}"
         data-api-host="https://chat.hostorio.com"
         data-chat-token="{$aiToken}">

    <h2 class="ho-ai-search-greeting">
        Hi, {$customer_name|default:'there'}! How can I help you today?
    </h2>

    <form class="ho-ai-search-form" role="search" autocomplete="off">
        <label class="sr-only" for="hoAiSearchInput">Ask a question</label>
        <input type="text"
               id="hoAiSearchInput"
               class="ho-ai-search-input"
               name="message"
               placeholder="Type what you're looking for or ask a question"
               autocomplete="off">
        <button type="submit" class="ho-ai-search-submit" aria-label="Send">
            <i class="fas fa-arrow-up" aria-hidden="true"></i>
        </button>
    </form>

    <div class="ho-ai-search-suggestions">
        <button type="button" class="ho-ai-search-chip" data-message="What domains do I have?">
            What domains do I have?
        </button>
        <button type="button" class="ho-ai-search-chip" data-message="When do my services expire?">
            When do my services expire?
        </button>
        <button type="button" class="ho-ai-search-chip" data-message="What's my billing status?">
            What's my billing status?
        </button>
    </div>

    <div class="ho-ai-search-answer" aria-live="polite"></div>

    <p class="ho-ai-search-foot">
        Prefer to browse? <a href="{$WEB_ROOT}/knowledgebase.php">Search the knowledgebase</a>
        or <a href="{$WEB_ROOT}/submitticket.php">open a ticket</a>.
    </p>

    {* Hidden data attributes for JavaScript *}
    <input type="hidden" id="customerData"
           data-customer-id="{$customer_id}"
           data-customer-email="{$customer_email}">

</section>

<script src="{$WEB_ROOT|cat:'/assets/js/hostorio-ai-search.js'}"></script>
