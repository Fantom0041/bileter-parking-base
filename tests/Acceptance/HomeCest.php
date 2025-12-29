<?php

declare(strict_types=1);

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

final class HomeCest
{
    /**
     * Test the initial state (Landing Page)
     * Checks if the form to enter a license plate is visible.
     */
    public function landingPageWorks(AcceptanceTester $I): void
    {
        $I->amOnPage('/');

        // Check for the main header (from index.php line 133)
        $I->see('Rozlicz parkowanie', 'h1');

        // Check for the input form elements
        $I->seeElement('#newTicketForm');
        $I->seeElement('#plateInput');
        $I->see('Start', 'button');
    }

    /**
     * Test the Ticket Details Page using SIMULATION
     * Uses `simulated=1` to force `index.php` to create mock data internally.
     * This ensures UI elements are tested even if the real API is down.
     */
    public function ticketDetailsPageWorksInSimulation(AcceptanceTester $I): void
    {
        $plateNumber = 'TEST_SIM';

        // Go directly to the ticket page with simulation enabled
        $I->amOnPage("/?ticket_id={$plateNumber}&simulated=1");

        // Verify we are NOT on the landing page form anymore
        $I->dontSee('Rozlicz parkowanie', 'h1');

        // Verify the License Plate is displayed
        $I->see($plateNumber, '#plateDisplay');

        // Verify key UI sections exist
        $I->see('Strefa', '.info-card-full');
        $I->see('Start', '.label');

        // Verify the Payment Sheet/Button exists
        $I->seeElement('#paymentSheet');
        $I->seeElement('#payButton');
    }

    /**
     * Test the Ticket Details Page using the LIVE API CODE PATH.
     * This request omits `simulated=1`.
     * 
     * NOTE: This test will pass if EITHER:
     * 1. The API connects successfully and shows the ticket.
     * 2. The API fails to connect and shows a specific API error message.
     * 
     * This confirms the application is attempting the real connection logic
     * rather than falling back to the "Input Form" silently.
     */
    public function liveApiPathWorks(AcceptanceTester $I): void
    {
        $plateNumber = 'TEST_LIVE';

        // Navigate WITHOUT simulated=1
        $I->amOnPage("/?ticket_id={$plateNumber}");

        // We should NOT see the generic "Start" form immediately if logic works,
        // unless there is a specific error caught.

        // Logic check:
        // If API connects -> We see ticket details (#plateDisplay)
        // If API fails -> We see an error message (.error-container or specific text)
        // We verify we are hitting one of these "Ticket Processed" states.

        $url = $I->grabFromCurrentUrl();

        // Check for specific error messages defined in index.php
        // "Bilet nie znaleziony w systemie" or "Błąd komunikacji"
        $isApiError = $I->tryToSee('Błąd');
        $isTicketFound = $I->tryToSee($plateNumber, '#plateDisplay');

        // Assert that we are NOT just sitting on the blank landing page without feedback
        if (!$isApiError && !$isTicketFound) {
            // If we don't see an error AND we don't see the ticket, 
            // maybe we are back at the form? 
            // Let's verify we at least tried to process it.
            $I->see('Rozlicz parkowanie', 'h1'); // Back at form
            // But we should see the error container if it failed
            $I->seeElement('.error-container');
        }
    }
}
