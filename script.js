document.addEventListener('DOMContentLoaded', () => {
    // State
    let currentFee = INITIAL_FEE;
    let addedMinutes = 0;

    // Elements
    const displayPrice = document.getElementById('displayPrice');
    const payButton = document.getElementById('payButton');
    const extensionChips = document.getElementById('extensionChips');
    const paymentSheet = document.getElementById('paymentSheet');
    const successOverlay = document.getElementById('successOverlay');
    const qrCode = document.getElementById('qrCode');
    const chips = document.querySelectorAll('.chip');

    // 1. Initial State Check
    if (INITIAL_FEE <= 0) {
        // Free period or already paid
        // If it's a free period, we might want to allow extending, 
        // but for simplicity, if fee is 0, we just allow "Free Exit" or show Paid.
        // If the user wants to extend during free period, that's an edge case.
        // For now, let's enable extension only if it's an active unpaid ticket.
        if (payButton.innerText === 'Pay Now') {
            extensionChips.style.display = 'flex';
        }
    } else {
        extensionChips.style.display = 'flex';
    }

    // 2. Handle Extension Chips
    chips.forEach(chip => {
        chip.addEventListener('click', () => {
            // Toggle active state
            if (chip.classList.contains('active')) {
                chip.classList.remove('active');
                addedMinutes -= parseInt(chip.dataset.add);
            } else {
                // Only allow one extension at a time for simplicity in this demo
                chips.forEach(c => {
                    if (c.classList.contains('active')) {
                        c.classList.remove('active');
                        addedMinutes -= parseInt(c.dataset.add);
                    }
                });

                chip.classList.add('active');
                addedMinutes += parseInt(chip.dataset.add);
            }

            updatePrice();
        });
    });

    function updatePrice() {
        // Calculate additional fee
        // addedMinutes -> hours * rate
        const additionalHours = Math.ceil(addedMinutes / 60);
        const additionalFee = additionalHours * HOURLY_RATE;

        currentFee = INITIAL_FEE + additionalFee;

        // Update UI
        displayPrice.innerText = currentFee.toFixed(2);

        if (currentFee > 0) {
            payButton.innerText = `Pay ${currentFee.toFixed(2)}`;
            payButton.disabled = false;
        } else {
            payButton.innerText = 'Free Exit';
            payButton.disabled = false;
        }
    }

    // 3. Handle Payment
    payButton.addEventListener('click', async () => {
        // UI Loading State
        const originalText = payButton.innerText;
        payButton.innerText = 'Processing...';
        payButton.disabled = true;

        try {
            const response = await fetch('api.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    ticket_id: TICKET_ID,
                    amount: currentFee
                })
            });

            const data = await response.json();

            if (data.success) {
                // Show Success
                qrCode.innerText = data.new_ticket_qr; // In real app, generate QR image
                successOverlay.classList.add('visible');

                // Hide payment sheet
                paymentSheet.style.transform = 'translate(-50%, 100%)';
            } else {
                alert('Payment failed: ' + data.message);
                payButton.innerText = originalText;
                payButton.disabled = false;
            }
        } catch (error) {
            console.error('Error:', error);
            alert('An error occurred. Please try again.');
            payButton.innerText = originalText;
            payButton.disabled = false;
        }
    });
});
