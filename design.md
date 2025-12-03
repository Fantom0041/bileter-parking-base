# Design Specifications: Mobile Parking Settlement

## 1. Design Philosophy
*   **"Thumb-First" Ergonomics:** Primary interactions (Payment, Extensions) are placed in the bottom 30% of the screen.
*   **Neomorphic Cleanliness:** Flat design with subtle depth (soft shadows), high border-radii (24px+), and generous whitespace.
*   **Visual Feedback:** Instant visual response to user actions (button presses, loading states).
*   **Reference Aesthetic:** Similar to Revolut/mPay/Apple Wallet. Clean, white/gray backgrounds with a strong primary accent color.

## 2. Color Palette & Typography

### 2.1. Color System (CSS Variables)
*   **Primary (Brand):** `#6A1B9A` (Deep Violet - derived from reference screenshots).
*   **Primary Active:** `#4A148C` (Darker shade for taps).
*   **Background (App):** `#F4F6F9` (Light Grey - reduces eye strain vs pure white).
*   **Surface (Cards):** `#FFFFFF` (Pure White).
*   **Text Primary:** `#1A1A1A` (Near Black).
*   **Text Secondary:** `#6D7278` (Slate Grey).
*   **Success:** `#00C853` (Vibrant Green for payment success).
*   **Alert/Error:** `#D50000` (Red).

### 2.2. Typography
*   **Font Family:** System stack (`-apple-system`, `BlinkMacSystemFont`, `Inter`, `Roboto`, sans-serif) for native feel and fast loading.
*   **Hierarchy:**
    *   **Price/Timer:** 42px, Bold (The focal point).
    *   **Headings:** 20px, Semi-Bold.
    *   **Body:** 16px, Regular.
    *   **Labels:** 12px, Medium, Uppercase (tracking +0.5px).

## 3. UI Component Structure

### 3.1. The "Plate" Component
A visual representation of the car's registration.
*   **Style:** Rectangular container, white background, slight border.
*   **Left Section:** Blue strip (Euroband) with "PL".
*   **Content:** The registration number (e.g., KRA1111) in a monospace-style font.

### 3.2. Info Cards
*   **Shape:** Rounded rectangle (Border-radius: 20px).
*   **Effect:** Soft shadow (`box-shadow: 0 4px 20px rgba(0,0,0,0.05)`).
*   **Content:**
    *   Entry Time (Icon: Clock)
    *   Parking Zone (Icon: Map Pin)

### 3.3. The Payment Control (Bottom Sheet)
*   **Position:** Fixed at the bottom of the viewport.
*   **Appearance:** White background, top-rounded corners (30px), heavy shadow lifting it off the page.
*   **Content:**
    *   **Total Due:** Large, centered price.
    *   **Extension Chips:** (If "Pay in Advance" is active) `[+30m]` `[+1h]` `[End of Day]`. Pill-shaped toggles.
    *   **CTA Button:** Full width, 56px height. Text: "Pay & Exit" or "Pay [Amount]".

## 4. Screen Flows & Layouts

### 4.1. Loading / Initialization
*   **Visual:** Center screen brand logo pulsing.
*   **Action:** Validates Ticket ID from URL in background.

### 4.2. Main Dashboard (Active Ticket)
1.  **Header:** Minimal. "Parking Details".
2.  **Hero:** The **License Plate** displayed prominently.
3.  **Status Indicator:**
    *   *Free Period:* Green pill badge "Time Remaining: 14m".
    *   *Paid Period:* Purple pill badge "Active".
4.  **Timer Circle:** A minimalist SVG circle indicating time elapsed vs time paid (inspired by the screenshot's "Start" button, but static/informative).
5.  **Details Grid:** Entry Time, Location.
6.  **Spacer:** Pushes content up.
7.  **Footer (Sticky):** The **Payment Control** section.

### 4.3. Extension Mode (Pay in Advance)
*   User taps "Extend Parking" (or similar toggle).
*   The "Total Due" updates dynamically based on the selection.
*   UI: Horizontal scroll list of time chips.

### 4.4. Success State (The "Ticket")
*   **Transition:** Smooth fade out of payment controls.
*   **Visual:** A large Green Checkmark animates in.
*   **The Exit Ticket:** A digital ticket appears (card style) containing:
    *   QR Code for exit.
    *   "Valid until: [Time]"
    *   "Receipt sent."
*   **Button:** "Close" or "Download PDF".

## 5. Technical UI Requirements (CSS)
*   **Box Sizing:** `border-box` globally.
*   **Flexbox/Grid:** Use Flexbox for layout alignment.
*   **Touch Targets:** All clickable elements must be at least 48x48px.
*   **Responsiveness:**
    *   `max-width: 480px` for the main container (keeps it looking like an app on desktop browsers).
    *   `margin: 0 auto` to center on desktop.
*   **Animations:** Use CSS transitions (`0.3s ease`) for all hover/active states and value changes.

## 6. Iconography
*   Use a lightweight SVG library (like Heroicons or Phosphor Icons) or inline SVGs.
*   Stroke width: 2px (matching modern iOS/Android styles).