<!DOCTYPE html>
<html lang="en">
<head>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Code Scanner</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        :root {
            --primary: #283555;
            --secondary: #3a4a6f;
            --accent: #ff6b35;
            --light: #f4f6f9;
            --dark: #2c3e50;
            --white: #ffffff;
            --gray: #6c757d;
            --success: #27ae60;
            --danger: #e74c3c;
            --border-color: #e8eef5;
            --transition: 0.3s ease;
        }

        /* General Styling */
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, var(--light) 0%, #ffffff 100%);
            margin: 0;
            padding: 20px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            color: var(--dark);
        }

        h1 {
            font-size: 2.5em;
            text-align: center;
            margin-bottom: 30px;
            color: var(--primary);
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
        }

        h1 i {
            color: var(--accent);
        }

        /* Video container styling */
        #video {
            width: 100%;
            max-width: 400px;
            border: 2px solid var(--border-color);
            border-radius: 12px;
            box-shadow: 0 8px 30px rgba(40, 53, 85, 0.15);
            background: var(--white);
        }

        /* Result container */
        #result {
            margin-top: 20px;
            font-size: 1.1em;
            color: var(--dark);
            font-weight: 600;
            text-align: center;
            height: 24px;
            min-height: 24px;
        }

        /* Modal Styling */
        .modal {
            display: none; /* Default hidden */
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            justify-content: center;
            align-items: center;
            transition: opacity var(--transition);
            opacity: 0;
            pointer-events: none;
            z-index: 1000;
        }

        .modal.show {
            opacity: 1;
            pointer-events: all;
            display: flex; /* Ensure it displays correctly */
        }

        .modal-content {
            color: var(--dark);
            padding: 40px;
            border-radius: 16px;
            width: 90%;
            max-width: 450px;
            box-shadow: 0 12px 50px rgba(40, 53, 85, 0.3);
            text-align: center;
            animation: fadeIn var(--transition);
            background: linear-gradient(135deg, var(--white) 0%, var(--light) 100%);
            border: 1px solid var(--border-color);
        }

        .modal-content h2 {
            font-size: 1.8em;
            margin-bottom: 20px;
            font-weight: 700;
            color: var(--primary);
            letter-spacing: 0.5px;
        }

        .modal-content p {
            margin-top: 10px;
            font-size: 1.1em;
            margin-bottom: 25px;
            line-height: 1.6;
            color: var(--dark);
        }

        .modal button {
            background: linear-gradient(135deg, var(--accent) 0%, #ff5722 100%);
            color: white;
            border: none;
            padding: 12px 32px;
            font-size: 1em;
            border-radius: 8px;
            cursor: pointer;
            transition: all var(--transition);
            box-shadow: 0 4px 15px rgba(255, 107, 53, 0.3);
            font-weight: 600;
        }

        .modal button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 107, 53, 0.4);
        }

        .modal button:active {
            transform: translateY(0);
        }

        /* Close Button */
        .modal .close-btn {
            background: var(--danger);
            color: white;
            padding: 12px 32px;
            font-size: 1em;
            border-radius: 8px;
            cursor: pointer;
            margin-top: 20px;
            transition: all var(--transition);
            border: none;
            font-weight: 600;
            box-shadow: 0 4px 15px rgba(231, 76, 60, 0.3);
        }

        .modal .close-btn:hover {
            background: #c62828;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(231, 76, 60, 0.4);
        }

        .footer {
            background: none; 
            color: var(--gray);
            text-align: center; 
            padding: 10px;
            position: fixed; 
            bottom: 0; 
            width: 100%;
            font-size: 0.9em;
        }

        .footer p {
            margin: 0; 
        }

        /* Animation for modal fade-in */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: scale(0.95);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        /* Center the body content */
        .content {
            text-align: center;
            width: 100%;
            max-width: 500px;
            margin: auto;
            padding-bottom: 60px;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            h1 {
                font-size: 1.8em;
                margin-bottom: 20px;
            }

            .modal-content {
                padding: 30px;
                width: 95%;
            }

            .modal-content h2 {
                font-size: 1.4em;
            }

            .modal-content p {
                font-size: 1em;
            }
        }
    </style>
</head>
<body>

    <div class="content">
        <h1><i class="fas fa-qrcode"></i> QR Code Scanner</h1>
        <!-- Video for QR Code Scanning -->
        <video id="video" width="300" height="300" autoplay></video>

        <!-- Result Display -->
        <div id="result"></div>

        <!-- Modal for QR Code Details -->
        <div id="modal" class="modal">
            <div id="modalContent" class="modal-content">
                <h2><i class="fas fa-check-circle" style="color: var(--success);"></i> Ticket Details</h2>
                <p id="qrDetails"></p>
                <button id="closeModal" class="close-btn"><i class="fas fa-times"></i> Close</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/jsqr/dist/jsQR.js"></script>
    <script>
        const video = document.getElementById('video');
        const resultDiv = document.getElementById('result');
        const canvas = document.createElement('canvas');
        const context = canvas.getContext('2d');
        const modal = document.getElementById('modal');
        const qrDetails = document.getElementById('qrDetails');
        const closeModal = document.getElementById('closeModal');

        // Function to start the camera and capture video
        async function startCamera() {
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
                video.srcObject = stream;
                video.setAttribute('playsinline', true); // Important for iOS
                video.play();
                requestAnimationFrame(scanQRCode);
            } catch (err) {
                console.error("Error accessing camera: ", err);
                resultDiv.innerText = 'Camera access denied. Please enable camera permissions.';
            }
        }

        // Function to scan QR code from video feed
        function scanQRCode() {
            if (video.readyState === video.HAVE_ENOUGH_DATA) {
                canvas.height = video.videoHeight;
                canvas.width = video.videoWidth;
                context.drawImage(video, 0, 0, canvas.width, canvas.height);

                // Scan the QR code using jsQR
                const imageData = context.getImageData(0, 0, canvas.width, canvas.height);
                const code = jsQR(imageData.data, canvas.width, canvas.height);

                if (code) {
                    const ticketData = code.data;

                    // Check if the QR code data is a URL and extract the ticket number
                    let ticketNumber = extractTicketNumber(ticketData);

                    if (ticketNumber) {
                        resultDiv.innerText = 'Ticket Number: ' + ticketNumber;

                        // Check ticket status by sending the ticket number to the server
                        checkTicketStatus(ticketNumber);
                        showModal(ticketNumber); // Show the modal with QR code data
                    } else {
                        resultDiv.innerText = 'Invalid QR Code Data.';
                    }
                } else {
                    resultDiv.innerText = 'Scanning...';
                }
            }
            requestAnimationFrame(scanQRCode);
        }

        // Function to extract the ticket number from the URL
        function extractTicketNumber(qrData) {
            const urlPattern = /ticket=([A-Za-z0-9\-]+)/;
            const match = qrData.match(urlPattern);
            if (match) {
                return match[1]; // Extracted ticket number
            }
            return null; // Return null if no ticket number found
        }

        // Function to check the ticket status from the server
        function checkTicketStatus(ticketNumber) {
            fetch('check_ticket_status.php?ticket_number=' + ticketNumber)
                .then(response => response.json())
                .then(data => {
                    if (data.status) {
                        qrDetails.innerHTML = `<strong>Full Name:</strong> ${data.full_name}<br><strong>Ticket Status:</strong> ${data.status}<br><strong>Ticket Number:</strong> ${ticketNumber}`;
                    } else {
                        qrDetails.innerHTML = 'Ticket not found or status is invalid.';
                    }
                })
                .catch(error => {
                    console.error('Error fetching ticket status:', error);
                    qrDetails.innerHTML = 'Error fetching ticket status.';
                });
        }

        // Function to display the modal with QR code details
        function showModal(data) {
            qrDetails.innerHTML = `<strong>QR Code Data:</strong> ${data}`;
            modal.classList.add('show'); // Add the 'show' class to make the modal visible
        }

        // Close modal when the close button is clicked
        closeModal.addEventListener('click', () => {
            modal.classList.remove('show'); // Remove the 'show' class to hide the modal
        });

        // Start the camera when the page loads
        startCamera();
    </script>

  <footer class="footer">
        <p>Created by: PSME ICTO and OJTs from Colegio de Montalban</p>
    </footer>
</body>
</html>
