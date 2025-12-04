# Refinement Tasks: Layout & Dynamic Fee Calculation

These tasks address the user's feedback regarding the UI layout (license plate position) and the logic for the time-selection spinner (server-side fee calculation with debounce).

## Phase 1: UI Layout Adjustments

### Task 1.1: Reorder Header Components
**Context:** The user requested that the License Plate component be moved to the very top, above the Entry Time and Zone information.
**File:** `index.php`
**Action:**
1.  Locate the `<section class="plate-section">`.
2.  Move it immediately **before** the `<section class="details-grid">`.
3.  Ensure the visual hierarchy flows as: Header -> License Plate -> Info Cards (Entry/Zone) -> Status -> Spinner.
**Acceptance Criteria:**
*   License plate is the first major element below the app header.
*   Margins/padding remain consistent (check `style.css` if the top margin of the plate section needs adjustment).

---

## Phase 2: Backend Logic (API)

### Task 2.1: Implement Fee Calculation Endpoint
**Context:** The frontend needs to ask the server to calculate the fee based on a proposed exit time, rather than calculating it in JavaScript.
**File:** `api.php`
**Action:**
1.  Add a new handling block for `action === 'calculate_fee'`.
2.  Input parameters: `ticket_id`, `extension_minutes` (or `target_exit_time`).
3.  Logic:
    *   Load the ticket.
    *   Calculate the projected duration: `(Now + Extension) - Entry Time`.
    *   Apply the pricing rules (Free period check, Hourly rate).
    *   Return JSON: `{ success: true, fee: X.XX, currency: "PLN" }`.
**Acceptance Criteria:**
*   Send a POST request via Postman/Curl with a ticket ID and extension time.
*   Receive the correct calculated fee in the response.

---

## Phase 3: Frontend Logic & Interactivity

### Task 3.1: Implement Debounced Spinner Logic
**Context:** When the user rotates the spinner, the system should not calculate the fee immediately on every pixel move. It must wait until the user stops interacting, then fetch the fee from the server.
**File:** `script.js`
**Action:**
1.  Create a `debounce` variable (timer).
2.  In the `updateSpinner` (or `drag`) function:
    *   Clear the existing timeout.
    *   Update the visual timer/hours on the screen immediately (client-side visual feedback).
    *   Set a new timeout (e.g., 500ms or 1000ms).
3.  Inside the timeout callback (executed when user stops dragging):
    *   Trigger a new function `fetchCalculatedFee(addedMinutes)`.

### Task 3.2: Implement Loading State & API Integration
**Context:** While the app is fetching the new price from the server, the user must be informed that the calculation is in progress.
**File:** `script.js`
**Action:**
1.  Create a visual state for "Calculating...":
    *   Change the `payButton` text to "Obliczanie opłaty..." (Calculating fee...).
    *   Disable the button.
    *   (Optional) Add a small loader/spinner icon or opacity change.
2.  Implement `fetchCalculatedFee`:
    *   Call `api.php` (POST `action: 'calculate_fee'`).
    *   On **Success**: Update `currentFee` variable and update the button text to "Zapłać [Kwota] PLN". Re-enable the button.
    *   On **Error**: Alert the user or reset to the initial fee.
**Acceptance Criteria:**
*   User spins the dial -> Button shows "Obliczanie...".
*   User releases dial -> After ~1 sec -> Button updates with the price returned from the server.

### Task 3.3: Update Spinner Visuals (Optional Refinement)
**Context:** The user mentioned "ustawiamy date i godzine zakonczenia" (setting the end date and time).
**File:** `script.js` / `index.php`
**Action:**
*   Currently, the spinner shows duration (e.g., `01:15`).
*   Update the label inside the spinner to show the **Estimated Exit Time** (e.g., `14:30`) based on `Current Time + Selected Duration`.