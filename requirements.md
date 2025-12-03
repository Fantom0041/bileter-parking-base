
# Project Requirements: Web-based Parking Ticket Settlement System

## 1. Project Overview
A lightweight, mobile-first web application built in plain PHP to allow users to scan a QR code on a parking ticket, view their current parking status, and settle the payment online. The design must be minimalist and highly readable.

## 2. Technology Stack
*   **Backend:** Plain PHP (no frameworks like Laravel/Symfony).
*   **Frontend:** HTML5, CSS3, Vanilla JavaScript.
*   **Database (Mock):** JSON file or SQLite (to simulate the parking system for demonstration purposes).
*   **External Libraries:** Minimal. Use a simple QR generator library if needed for the "new ticket" generation, otherwise standard web APIs.

## 3. Core Functional Requirements

### 3.1. Entry Point (QR Code Handling)
*   The application is accessed via a URL containing a Ticket ID (e.g., `index.php?ticket_id=12345`).
*   This URL simulates the user scanning a physical QR code printed on a parking ticket.
*   **Validation:** If the ID is invalid or missing, display a clear error message.

### 3.2. Ticket Information Display
Upon loading the valid Ticket ID, the system must retrieve and display:
*   **Registration Number:** (e.g., KRA1111).
*   **Date of Issue:** (Start time of parking).
*   **Current Fee:** Calculated based on the duration.
*   **Status Message:**
    *   If `fee == 0`: Display "Free Parking Period" (Okres darmowy).
    *   If `fee > 0`: Display the amount due.

### 3.3. Payment & Settlement Logic
*   **Action:** Provide a prominent "Pay / Settle" button.
*   **Payment Simulation:** Clicking the button triggers a mock payment process.
*   **Post-Payment Actions:**
    1.  **Log Receipt:** Save a record of the transaction. The system must use a `config.ini` file to determine which "User" and "Station" (Stanowisko) to assign the receipt to.
    2.  **Ticket Swap:**
        *   Mark the current "entry" ticket as invalid/paid.
        *   Generate/Issue a new "Exit Ticket" (valid for leaving the parking lot).
    3.  **Confirmation:** Show a success screen with the new status (e.g., "Paid - You may exit").

### 3.4. Pay in Advance (Extended Feature)
*   Allow the user to pay for a future duration instead of just the current time.
*   **UI:** A selection input (e.g., "Extend by: 1h, 2h, 3 days").
*   **Logic:** Calculate the fee for the selected future period.
*   **Action:** "Pay in Advance" button.

## 4. Configuration (INI File)
The application must read settings from a `config.ini` file containing:
*   **Station ID:** To identify the physical location.
*   **Operator User:** The system user associated with online payments.
*   **Pricing Rules:** (Optional) Hourly rate to calculate the fee.

## 5. UI/UX Design Guidelines
*   **Style:** Minimalist, clean, and modern.
*   **Reference:** Similar to modern parking apps (e.g., mPay style shown in reference images) - clean white background, clear typography, rounded buttons.
*   **Mobile-First:** The layout must be optimized for smartphones.
*   **Color Palette:** White, Black, and a primary accent color (e.g., Purple `#6A1B9A` or similar) for the Call-to-Action buttons.

## 6. Data Structure (Mock for Development)
Since there is no live API connection, create a `tickets.json` file to act as the database.
*   **Structure Example:**
    ```json
    {
      "12345": {
        "plate": "KRA1111",
        "entry_time": "2023-10-27 10:00:00",
        "status": "active"
      }
    }
    ```

## 7. Deliverables
1.  `index.php`: Main logic and view.
2.  `style.css`: Styling.
3.  `script.js`: Frontend interactions and AJAX for payment.
4.  `config.ini`: Configuration file.
5.  `data.json`: Mock database.
