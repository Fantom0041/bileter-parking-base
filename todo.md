Here is the updated configuration to include Docker. I have added a **Phase 0** to the Todo List and provided the necessary Docker configuration files (`Dockerfile` and `docker-compose.yml`).

### Updated Todo List for AI Agent

```markdown
# AI Implementation Todo List

## Phase 0: Environment Setup (Docker)
*   **Context:** Setup the containerized environment to ensure the app runs consistently with all dependencies.

1.  [ ] **Create `Dockerfile`:**
    *   Use `php:8.2-apache` base image.
    *   Enable `mod_rewrite`.
    *   Set ownership of the working directory to `www-data` to allow writing to `data.json`.
2.  [ ] **Create `docker-compose.yml`:**
    *   Map Host Port **8080** (Custom) to Container Port **80**.
    *   Mount the current directory to `/var/www/html` for live editing.
3.  [ ] **Create `.dockerignore`:**
    *   Exclude `.git`, `.idea`, etc.
4.  [ ] **Launch Application:**
    *   Run `docker-compose up -d --build`.
    *   Verify access at `http://localhost:8080`.

## Phase 1: Project Initialization & Data Layer
*   **Context:** Setup the file structure and mock data as defined in `requirements.md` (Section 6 & 7).

5.  [ ] **Create File Structure:**
    *   `index.php`, `api.php`, `style.css`, `script.js` (empty files).
    *   `config.ini` (Configuration).
    *   `data.json` (Mock Database).

6.  [ ] **Implement Configuration (`config.ini`):**
    *   Define `station_id`, `operator_user`, and `hourly_rate` (e.g., 5.00).

7.  [ ] **Create Mock Database (`data.json`):**
    *   Populate with 3 test cases (Short/Free, Long/Paid, Closed).
    *   **Critical:** Ensure `data.json` has write permissions (666 or 777) so the Docker container can update it.

Here is the comprehensive Todo List designed for an AI Agent to execute the project. It references the previously created `requirements.md` and `design.md` files to ensure all constraints are met.

# AI Implementation Todo List

## Phase 1: Project Initialization & Data Layer
*   **Context:** Setup the file structure and mock data as defined in `requirements.md` (Section 6 & 7).

1.  [ ] **Create File Structure:**
    *   `index.php` (Main entry point)
    *   `api.php` (Endpoint for AJAX payment requests)
    *   `style.css` (Styles)
    *   `script.js` (Frontend logic)
    *   `config.ini` (Configuration)
    *   `data.json` (Mock Database)

2.  [ ] **Implement Configuration (`config.ini`):**
    *   Define `station_id`, `operator_user`, and `hourly_rate` (e.g., 5.00).

3.  [ ] **Create Mock Database (`data.json`):**
    *   Populate with at least 3 test cases:
        *   **Case A:** Active ticket, short duration (Free period, <15 mins).
        *   **Case B:** Active ticket, long duration (Needs payment).
        *   **Case C:** Already paid ticket (Status: closed).
    *   *Reference:* `requirements.md` Section 6.

## Phase 2: Backend Logic (Plain PHP)
*   **Context:** Implement the server-side logic without frameworks.

4.  [ ] **Implement `index.php` (Read Logic):**
    *   Load `config.ini` and `data.json`.
    *   Validate `$_GET['ticket_id']`.
    *   Calculate parking duration (Current Time - Entry Time).
    *   Calculate Fee:
        *   If duration < 15 mins (or defined threshold), Fee = 0.
        *   Else, calculate based on `hourly_rate`.
    *   Pass these variables ($plate, $fee, $status, $entry_time) to the HTML view.

5.  [ ] **Implement `api.php` (Write/Payment Logic):**
    *   Accept POST requests (JSON) containing `ticket_id` and `amount`.
    *   **Action:** Update the specific record in `data.json` to `status: paid`.
    *   **Action:** Generate a "Receipt" log (append to a `receipts.log` or a new array in json).
    *   **Action:** Return a JSON response `{success: true, new_ticket_qr: "..."}`.

## Phase 3: Frontend UI Structure (HTML/CSS)
*   **Context:** Build the Mobile-First UI based on `design.md`.

6.  [ ] **Build HTML Skeleton (`index.php`):**
    *   Add viewport meta tag for mobile.
    *   Link `style.css` and `script.js`.
    *   Create container with `max-width: 480px`.

7.  [ ] **Implement UI Components:**
    *   **Hero:** License Plate Component (Blue strip "PL", Monospace text).
    *   **Info Cards:** Entry time, Zone.
    *   **Status Indicator:** Visual circle/badge (Green for free, Purple for paid).
    *   **Footer/Bottom Sheet:** Sticky container for the Payment controls.

8.  [ ] **Apply Styling (`style.css`):**
    *   Define CSS Variables from `design.md` Section 2.1 (Deep Violet `#6A1B9A`, Background `#F4F6F9`).
    *   Apply Neomorphic styles (soft shadows, rounded corners).
    *   Ensure "Thumb-First" ergonomics (Buttons at the bottom).

## Phase 4: Interactivity & Payment Flow (JS)
*   **Context:** Handle the user experience and AJAX calls.

9.  [ ] **Implement State Handling:**
    *   If `fee == 0`, change Button text to "Validate & Exit" (Free).
    *   If `fee > 0`, change Button text to "Pay [Amount]".

10. [ ] **Implement "Pay in Advance" Logic (Optional/Extended):**
    *   Add UI chips: `[+1h]`, `[+2h]`.
    *   On click, update the displayed Fee in the DOM temporarily.

11. [ ] **Implement Payment Action:**
    *   On Button Click -> Show "Processing" animation.
    *   Fetch `api.php` via POST.
    *   Handle Response:
        *   **Success:** Hide Payment Sheet, Fade in "Green Checkmark" and "Exit Ticket/QR".
        *   **Error:** Show alert.

## Phase 5: Final Review & Polish
*   **Context:** Ensure the app looks like the reference screenshots and meets the requirements.

12. [ ] **Visual Verify:**
    *   Does the License Plate look like a Polish plate?
    *   Is the "Start/Timer" circle present (static or visual)?
    *   Is the design clean and white?

13. [ ] **Edge Case Handling:**
    *   Add check: If Ticket ID is not found, show a clean 404 error page in the app style.
---

### Docker Configuration Files

Create these files in the root directory of the project.

#### 1. `Dockerfile`
This sets up a lightweight Apache + PHP server and ensures the web server has permission to write to your JSON "database".

```dockerfile
FROM php:8.2-apache

# Enable Apache mod_rewrite for potential future URL routing
RUN a2enmod rewrite

# Set the working directory
WORKDIR /var/www/html

# Copy application files to the container
COPY . /var/www/html

# Set permissions:
# We need to give the Apache user (www-data) write access to the directory
# so it can update data.json and write receipts.
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Expose port 80 (internal)
EXPOSE 80
```

#### 2. `docker-compose.yml`
This simplifies the launch process and maps the custom port.

```yaml
services:
  app:
    build: .
    container_name: parking-app
    ports:
      - "8080:80" # Maps localhost:8080 to container:80
    volumes:
      # Mounts your local folder to the container so code changes apply instantly
      - .:/var/www/html
    environment:
      - APACHE_DOCUMENT_ROOT=/var/www/html
    restart: unless-stopped
```

#### 3. `.dockerignore`
Keeps the build clean.

```text
.git
.gitignore
.idea
.vscode
docker-compose.yml
README.md
```

### How to Run
The AI Agent (or you) will execute the following:

1.  **Start:** `docker-compose up -d --build`
2.  **Access:** Open browser to `http://localhost:8080/index.php?ticket_id=12345` (once the code is written).
3.  **Stop:** `docker-compose down`