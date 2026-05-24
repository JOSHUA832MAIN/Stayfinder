// ========================================
// owner_dashboard.js - Main Dashboard Script
// ========================================

function showMessage(message, type) {
    const msgBox = document.getElementById('msgBox');
    msgBox.className = `alert alert-${type === 'success' ? 'success' : 'danger'} alert-dismissible fade show`;
    msgBox.innerHTML = `
        <div style="display: flex; align-items: center; gap: 10px;">
            <i class="fas fa-${type === 'success' ? 'check-circle' : 'times-circle'}" style="font-size: 1.5rem;"></i>
            <span>${message}</span>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    `;
    msgBox.style.display = 'block';

    setTimeout(() => {
        msgBox.style.display = 'none';
    }, 5000);
}

function loadRooms() {
    const roomsGrid = document.getElementById('roomsGrid');

    const formData = new FormData();
    formData.append('action', 'get_rooms');

    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(rooms => {
        roomsGrid.innerHTML = '';

        if (rooms.length === 0) {
            roomsGrid.innerHTML = `
                <div class="col-12 text-center py-5">
                    <i class="fas fa-home text-muted" style="font-size: 3rem;"></i>
                    <p class="text-muted mt-3">No rooms created yet. Click "Add New Room" to get started.</p>
                </div>
            `;
            return;
        }

        rooms.forEach(room => {
            const statusClass = getRoomStatusClass(room);
            const cardClass = getCardClass(statusClass);

           const roomCard = `
    <div class="col-md-4 col-lg-3 mb-3">
        <div class="card ${cardClass} room-card" data-room="${room.room_number}"
             onclick="openRoomModal(${room.room_number})"
             style="cursor: pointer;">
            <div class="card-body text-center">
                <h5 class="card-title">
                    ${room.room_type === 'faculty' ? 'Faculty ' : ''}Room ${room.room_number}
                </h5>
                <p class="text-muted mb-2" style="font-size: 0.9rem;">
                    Room ID: ${room.room_id || 'N/A'}
                </p>
                <div class="room-card-footer">
                    <small>${room.occupied_beds} / ${room.beds_count} Beds</small>
                    ${room.price ? `<small class="price-tag">₱${parseFloat(room.price).toFixed(2)}</small>` : ''}
                    
                  
                </div>
                <button class="btn btn-warning w-100 mt-2">Manage</button>
            </div>
        </div>
    </div>
`;

            roomsGrid.innerHTML += roomCard;
        });
    })
    .catch(error => {
        console.error('Error loading rooms:', error);
        showMessage('Error loading rooms', 'error');
    });
}

function getRoomStatusClass(room) {
    if (room.beds_count === 0) return 'no-beds';
    if (room.occupied_beds >= room.beds_count) return 'occupied';
    return 'available';
}

function getCardClass(statusClass) {
    switch (statusClass) {
        case 'available': return 'border-success bg-success bg-opacity-10';
        case 'occupied': return 'border-warning bg-warning bg-opacity-10';
        case 'no-beds': return 'border-secondary bg-secondary bg-opacity-10';
        default: return 'border-secondary';
    }
}

function openRoomModal(roomNumber) {
    const houseId = window.phpVars.houseId;
    window.location.href = `roomanagement.php?house_id=${houseId}&room_number=${roomNumber}`;
}

function addRoom() {
    const formData = new FormData();
    formData.append('action', 'add_room');

    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage(data.message, 'success');
            loadRooms();
        } else {
            showMessage(data.error || 'Failed to add room', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showMessage('Network error occurred', 'error');
    });
}

function removeRoom() {
    const roomNumber = prompt('Enter room number to remove:');
    if (!roomNumber) return;

    const formData = new FormData();
    formData.append('action', 'check_room_occupancy');
    formData.append('room_number', roomNumber);

    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (data.has_occupants) {
                showMessage(`❌ Cannot remove Room ${roomNumber} because it has ${data.occupied_beds} occupied bed(s). Please remove tenants first.`, 'error');
                return;
            }

            if (!confirm(`Are you sure you want to remove Room ${roomNumber}? This action cannot be undone.`)) return;

            const removeFormData = new FormData();
            removeFormData.append('action', 'remove_room');
            removeFormData.append('room_number', roomNumber);

            fetch('', {
                method: 'POST',
                body: removeFormData
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    showMessage(result.message, 'success');
                    loadRooms();
                } else {
                    showMessage(result.error || 'Failed to remove room', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('Network error occurred', 'error');
            });

        } else {
            showMessage('Error checking room occupancy', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showMessage('Network error occurred', 'error');
    });
}

function openTenantRequestsModal() {
    const modal = new bootstrap.Modal(document.getElementById('tenantRequestsModal'));
    modal.show();
}

function showPendingRequests() {
    const formData = new FormData();
    formData.append('action', 'get_tenant_requests');
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        const container = document.getElementById('tenantRequestsList');
        
        if (data.success && data.requests.length > 0) {
            container.innerHTML = data.requests.map(request => {
                let profileDisplay = '';
                if (request.profile_img && request.profile_img.trim() !== '') {
                    profileDisplay = `<img src="../${request.profile_img}" alt="Profile" 
                                         style="width: 50px; height: 50px; border-radius: 50%; 
                                         object-fit: cover; border: 3px solid #ffc107; 
                                         margin-right: 15px; box-shadow: 0 2px 8px rgba(0,0,0,0.2);">`;
                } else {
                    const initial = request.full_name.charAt(0).toUpperCase();
                    profileDisplay = `<div style="width: 50px; height: 50px; border-radius: 50%; 
                                         background: linear-gradient(135deg, #ffc107, #ff9800); 
                                         display: inline-flex; align-items: center; 
                                         justify-content: center; font-size: 24px; 
                                         font-weight: 700; color: #000; 
                                         border: 3px solid #e0a800; margin-right: 15px;
                                         box-shadow: 0 2px 8px rgba(0,0,0,0.2);">${initial}</div>`;
                }
                
                return `
                    <div class="card mb-3 border-warning shadow-sm">
                        <div class="card-header bg-warning text-dark">
                            <div class="d-flex align-items-center">
                                ${profileDisplay}
                                <div>
                                    <h5 class="mb-0">
                                        <i class="fas fa-user me-2"></i>${request.full_name}
                                    </h5>
                                    <small class="text-muted">${request.email}</small>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <p class="mb-2"><strong>Booking ID:</strong> #${request.id}</p>
                                    <p class="mb-2"><strong>Full Name:</strong> ${request.full_name}</p>
                                    <p class="mb-2"><strong>Age:</strong> ${request.age || 'N/A'}</p>
                                    <p class="mb-2"><strong>Gender:</strong> ${request.gender ? request.gender.charAt(0).toUpperCase() + request.gender.slice(1) : 'N/A'}</p>
                                </div>
                                <div class="col-md-6">
                                    <p class="mb-2"><strong>Address:</strong> ${request.address || 'N/A'}</p>
                                    <p class="mb-2"><strong>Phone:</strong> ${request.phone || 'N/A'}</p>
                                    <p class="mb-2"><strong>Email:</strong> ${request.email}</p>
                                    <p class="mb-2"><strong>Start Date:</strong> ${new Date(request.start_date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })}</p>
                                </div>
                            </div>
                            <hr>
                            <div class="row">
                                <div class="col-md-12">
                                    <p class="mb-2"><strong>Room:</strong> ${request.room_number}</p>
                                    <p class="mb-2"><strong>Bed:</strong> ${request.bed_number}</p>
                                </div>
                            </div>
                            <div class="btn-group w-100 mt-3">
                                <button class="btn btn-success" onclick="handleTenantRequest(${request.id}, 'accepted')">
                                    <i class="fas fa-check me-2"></i> Accept
                                </button>
                                <button class="btn btn-danger" onclick="handleTenantRequest(${request.id}, 'declined')">
                                    <i class="fas fa-times me-2"></i> Decline
                                </button>
                            </div>
                        </div>
                    </div>
                `;
            }).join('');
        } else {
            container.innerHTML = '<div class="text-center text-muted py-4"><i class="fas fa-inbox me-2"></i>No pending requests</div>';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showMessage('Failed to load pending requests.', 'error');
    });
}

function showModalMessage(message, type) {
    let msgContainer = document.getElementById('modalMessageContainer');
    if (!msgContainer) {
        msgContainer = document.createElement('div');
        msgContainer.id = 'modalMessageContainer';
        msgContainer.style.cssText = 'position: fixed; top: 20px; left: 50%; transform: translateX(-50%); z-index: 9999; max-width: 500px;';
        document.body.appendChild(msgContainer);
    }

    msgContainer.innerHTML = `
        <div class="alert alert-${type === 'success' ? 'success' : 'danger'} alert-dismissible fade show shadow-lg" role="alert">
            <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-triangle'} me-2"></i>
            <strong>${message}</strong>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    `;

    setTimeout(() => {
        msgContainer.innerHTML = '';
    }, 3000);
}

function handleTenantRequest(requestId, decision) {
    const acceptBtn = event.target;
    const declineBtn = event.target.parentElement.querySelector('.btn-danger, .btn-success');
    acceptBtn.disabled = true;
    if (declineBtn) declineBtn.disabled = true;

    const formData = new FormData();
    formData.append('action', 'handle_tenant_request');
    formData.append('request_id', requestId);
    formData.append('decision', decision);

    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const messageType = data.decision === 'accepted' ? 'success' : 'danger';

            showModalMessage(data.message, messageType);
            showMessage(data.message, messageType);

            setTimeout(() => {
                showPendingRequests();
                loadRooms();
            }, 1500);
        } else {
            showModalMessage(data.error || 'Failed to process request', 'error');
            showMessage(data.error || 'Failed to process request', 'error');

            acceptBtn.disabled = false;
            if (declineBtn) declineBtn.disabled = false;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showModalMessage('Network error occurred', 'error');
        showMessage('Network error occurred', 'error');

        acceptBtn.disabled = false;
        if (declineBtn) declineBtn.disabled = false;
    });
}

// ========================================
// Room Pricing Management
// ========================================

let currentRoomForPricing = null;

function openRoomPriceModal(roomNumber) {
    currentRoomForPricing = roomNumber;
    document.getElementById('roomLocation').textContent = `Room ${roomNumber}`;
    document.getElementById('roomPrice').value = '';

    const modal = new bootstrap.Modal(document.getElementById('roomPriceModal'));
    modal.show();
}

function saveRoomPrice() {
    if (!currentRoomForPricing) return;

    const priceInput = document.getElementById('roomPrice');
    const price = priceInput.value;
    if (!price || parseFloat(price) < 0) {
        alert('Please enter a valid non-negative price.');
        priceInput.focus();
        return;
    }

    const formData = new FormData();
    formData.append('action', 'set_room_price');
    formData.append('room_number', currentRoomForPricing);
    formData.append('price', price);

    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage(data.message, 'success');
            bootstrap.Modal.getInstance(document.getElementById('roomPriceModal')).hide();
            loadRooms();
        } else {
            showMessage(data.error || 'Failed to set price', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showMessage('Network error occurred', 'error');
    });
}

// ========================================
// Room Amenities Management
// ========================================

let currentRoomForAmenities = null;

function openAmenitiesModal(roomNumber) {
    currentRoomForAmenities = roomNumber;
    document.getElementById('amenitiesRoomTitle').textContent = `Room ${roomNumber}`;
    document.getElementById('amenitiesText').value = '';

    const modal = new bootstrap.Modal(document.getElementById('amenitiesModal'));
    modal.show();
}

function saveRoomAmenities() {
    if (!currentRoomForAmenities) return;

    const amenities = document.getElementById('amenitiesText').value;
    const formData = new FormData();
    formData.append('action', 'set_room_amenities');
    formData.append('room_number', currentRoomForAmenities);
    formData.append('amenities', amenities);

    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage(data.message, 'success');
            bootstrap.Modal.getInstance(document.getElementById('amenitiesModal')).hide();
        } else {
            showMessage(data.error || 'Failed to save amenities', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showMessage('Network error occurred', 'error');
    });
}

// ========================================
// Google Maps Management
// ========================================

let map, marker;

function initMap() {
    const defaultCenter = { lat: 8.359995345948724, lng: 123.84327331569628 };
    
    const initialLat = window.phpVars.hasMapLocation ? window.phpVars.mapLat : defaultCenter.lat;
    const initialLng = window.phpVars.hasMapLocation ? window.phpVars.mapLng : defaultCenter.lng;
    
    map = new google.maps.Map(document.getElementById('ownerMap'), {
        center: { lat: initialLat, lng: initialLng },
        zoom: 17,
        mapTypeId: google.maps.MapTypeId.HYBRID,
        styles: [
            {
                "featureType": "all",
                "elementType": "labels",
                "stylers": [{ "visibility": "on" }]
            },
            {
                "featureType": "all", 
                "elementType": "geometry",
                "stylers": [{ "visibility": "on" }]
            },
            {
                "featureType": "all",
                "elementType": "labels.text.fill",
                "stylers": [{ "visibility": "on" }]
            },
            {
                "featureType": "all",
                "elementType": "labels.text.stroke", 
                "stylers": [{ "visibility": "on" }]
            },
            {
                "featureType": "poi",
                "elementType": "all",
                "stylers": [{ "visibility": "on" }]
            },
            {
                "featureType": "poi",
                "elementType": "labels",
                "stylers": [{ "visibility": "on" }]
            },
            {
                "featureType": "road",
                "elementType": "all",
                "stylers": [{ "visibility": "on" }]
            },
            {
                "featureType": "transit",
                "elementType": "all", 
                "stylers": [{ "visibility": "on" }]
            },
            {
                "featureType": "landscape",
                "elementType": "all",
                "stylers": [{ "visibility": "on" }]
            },
            {
                "featureType": "administrative",
                "elementType": "all",
                "stylers": [{ "visibility": "on" }]
            }
        ],
        mapTypeControl: true,
        mapTypeControlOptions: {
            style: google.maps.MapTypeControlStyle.HORIZONTAL_BAR,
            position: google.maps.ControlPosition.TOP_CENTER,
            mapTypeIds: [
                google.maps.MapTypeId.ROADMAP,
                google.maps.MapTypeId.SATELLITE,
                google.maps.MapTypeId.HYBRID,
                google.maps.MapTypeId.TERRAIN
            ]
        },
        zoomControl: false,
        zoomControlOptions: {
            position: google.maps.ControlPosition.RIGHT_CENTER
        },
        streetViewControl: true,
        streetViewControlOptions: {
            position: google.maps.ControlPosition.RIGHT_BOTTOM
        },
        fullscreenControl: true,
        fullscreenControlOptions: {
            position: google.maps.ControlPosition.RIGHT_TOP
        }
    });

    if (window.phpVars.hasMapLocation && window.phpVars.mapLat && window.phpVars.mapLng) {
        marker = new google.maps.Marker({
            position: { lat: window.phpVars.mapLat, lng: window.phpVars.mapLng },
            map: map,
            icon: {
                url: '../img/icons/house_9408891.png',
                scaledSize: new google.maps.Size(50, 50),
                anchor: new google.maps.Point(25, 50)
            },
            title: 'Your Boarding House Location',
            animation: google.maps.Animation.DROP
        });
        
        map.setCenter({ lat: window.phpVars.mapLat, lng: window.phpVars.mapLng });
        map.setZoom(17);
    }

    map.addListener('click', function(e) {
        setMapLocation(e.latLng.lat(), e.latLng.lng());
    });
}

function initSearchBox() {
    const input = document.createElement('input');
    input.type = 'text';
    input.placeholder = 'Search location on map...';
    input.style.cssText = `
        background-color: #fff;
        border: 2px solid #fff;
        border-radius: 8px;
        box-shadow: 0 2px 6px rgba(0,0,0,0.3);
        margin: 10px;
        padding: 8px 12px;
        font-size: 14px;
        width: 300px;
        position: absolute;
        top: 10px;
        left: 10px;
        z-index: 1000;
    `;

    map.controls[google.maps.ControlPosition.TOP_LEFT].push(input);

    const searchBox = new google.maps.places.SearchBox(input);

    map.addListener('bounds_changed', function() {
        searchBox.setBounds(map.getBounds());
    });

    searchBox.addListener('places_changed', function() {
        const places = searchBox.getPlaces();

        if (places.length == 0) return;

        const place = places[0];
        if (!place.geometry) return;

        map.setCenter(place.geometry.location);
        map.setZoom(17);

        setMapLocation(place.geometry.location.lat(), place.geometry.location.lng());
    });
}

function saveMapLocation() {
    if (!window.tempMapCoords) {
        showMapMessage('Please click on the map to set a new location first.', 'warning');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'save_map_location');
    formData.append('lat', window.tempMapCoords.lat);
    formData.append('lng', window.tempMapCoords.lng);

    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMapMessage('Location saved successfully!', 'success');
            window.phpVars.hasMapLocation = true;
            window.phpVars.mapLat = window.tempMapCoords.lat;
            window.phpVars.mapLng = window.tempMapCoords.lng;
            window.tempMapCoords = null;
        } else {
            showMapMessage('Failed to save location', 'danger');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showMapMessage('Network error occurred', 'danger');
    });
}

function clearMapLocation() {
    if (!window.phpVars.hasMapLocation) {
        showMapMessage('No location is currently set.', 'info');
        return;
    }
    if (!confirm('Are you sure you want to clear the boarding house location?')) return;

    if (marker) {
        marker.setMap(null);
        marker = null;
    }

    const formData = new FormData();
    formData.append('action', 'save_map_location');
    formData.append('lat', 'null');
    formData.append('lng', 'null');

    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.getElementById('saveLocationBtn').style.display = 'none';
            document.getElementById('clearLocationBtn').style.display = 'none';
            window.phpVars.hasMapLocation = false;
            window.phpVars.mapLat = null;
            window.phpVars.mapLng = null;
            window.tempMapCoords = null;
            showMapMessage('Location cleared successfully!', 'success');
        } else {
            showMapMessage('Failed to clear location', 'danger');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showMapMessage('Network error occurred', 'danger');
    });
}

function setMapLocation(lat, lng) {
    if (marker) {
        marker.setMap(null);
    }

    marker = new google.maps.Marker({
        position: { lat: lat, lng: lng },
        map: map,
        icon: {
            url: '../img/icons/house_9408891.png',
            scaledSize: new google.maps.Size(50, 50),
            anchor: new google.maps.Point(25, 50)
        },
        title: 'Your Boarding House Location',
        animation: google.maps.Animation.DROP
    });

    window.tempMapCoords = { lat: lat, lng: lng };

    document.getElementById('coordinatesDisplay').classList.add('active');
    document.getElementById('latitudeDisplay').textContent = lat.toFixed(6);
    document.getElementById('longitudeDisplay').textContent = lng.toFixed(6);

    document.getElementById('saveLocationBtn').style.display = 'inline-block';
    document.getElementById('clearLocationBtn').style.display = 'inline-block';

    showMapMessage('Location selected! Click "Save Location" to confirm.', 'info');
}

function showMapMessage(message, type) {
    const msgBox = document.getElementById('mapMsg');
    msgBox.className = `alert alert-${type} alert-dismissible fade show`;
    msgBox.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    msgBox.style.display = 'block';

    setTimeout(() => {
        if (msgBox.parentNode) {
            msgBox.style.display = 'none';
        }
    }, 3000);
}

// ========================================
// 360° Panorama Viewer
// ========================================

let currentPanoramaImages = [];
let currentPanoramaIndex = 0;
let currentZoom = 1;
let panoramaViewerInitialized = false;
let isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
let isFullscreen = false;
let touchStartDistance = 0;
let lastTouchTime = 0;

function open360Viewer(index = 0) {
    console.log('Opening 360° viewer with images:', window.phpVars.panoramaImages);

    if (!window.phpVars.panoramaImages || window.phpVars.panoramaImages.length === 0) {
        alert('No panorama images available');
        return;
    }
    currentPanoramaImages = window.phpVars.panoramaImages;
    currentPanoramaIndex = index;
    currentZoom = 1;

    const viewer = document.getElementById('panoramaViewer');
    const navigation = document.getElementById('panoramaNavigation');
    const loading = document.getElementById('panoramaLoading');
    const instructions = document.getElementById('panoramaInstructions');

    viewer.style.display = 'block';
    loading.style.display = 'block';

    if (isMobile && instructions) {
        instructions.style.display = 'block';
        setTimeout(() => {
            instructions.style.display = 'none';
        }, 5000);
    }
    navigation.innerHTML = '';
    if (currentPanoramaImages.length > 1) {
        currentPanoramaImages.forEach((image, idx) => {
            const btn = document.createElement('button');
            btn.className = `panorama-nav-btn ${idx === index ? 'active' : ''}`;
            btn.textContent = `View ${idx + 1}`;
            btn.onclick = () => switchPanorama(idx);
            navigation.appendChild(btn);
        });
    }

    setTimeout(() => {
        initializePanoramaViewer();
    }, 300);
}

function initializePanoramaViewer() {
    const scene = document.getElementById('panoramaScene');
    const sky = document.getElementById('panoramaSky');
    const camera = document.getElementById('panoramaCamera');
    const loading = document.getElementById('panoramaLoading');

    if (!scene || !sky || !camera) {
        console.error('A-Frame elements not found:', { scene: !!scene, sky: !!sky, camera: !!camera });
        loading.style.display = 'none';
        return;
    }
    console.log('Initializing panorama viewer for', isMobile ? 'mobile' : 'desktop');
    if (isMobile) {
        camera.setAttribute('look-controls', {
            enabled: true,
            touchEnabled: true,
            magicWindowTrackingEnabled: false,
            mouseEnabled: true,
            pointerLockEnabled: false,
            touchSensitivity: 0.5
        });
    } else {
        camera.setAttribute('look-controls', {
            enabled: true,
            touchEnabled: false,
            magicWindowTrackingEnabled: false,
            mouseEnabled: true,
            pointerLockEnabled: false,
        });
    }
    loadPanorama(currentPanoramaIndex);
    resetView();

    if (isMobile) {
        const viewer = document.getElementById('panoramaViewer');
        viewer.addEventListener('touchstart', handleTouchStart, { passive: false });
        viewer.addEventListener('touchmove', handleTouchMove, { passive: false });
        viewer.addEventListener('touchend', handleTouchEnd, { passive: false });
    }
    panoramaViewerInitialized = true;

    setTimeout(() => {
        loading.style.display = 'none';
    }, 1000);
}

function handleTouchStart(event) {
    if (event.touches.length === 2) {
        event.preventDefault();
        touchStartDistance = getTouchDistance(event.touches);
    }
    lastTouchTime = Date.now();
}

function handleTouchMove(event) {
    if (event.touches.length === 2) {
        event.preventDefault();
        const currentDistance = getTouchDistance(event.touches);
        const delta = currentDistance - touchStartDistance;

        if (Math.abs(delta) > 10) {
            if (delta > 0) {
                zoomIn();
            } else {
                zoomOut();
            }
            touchStartDistance = currentDistance;
        }
    }
}

function handleTouchEnd(event) {
    touchStartDistance = 0;
}

function getTouchDistance(touches) {
    const dx = touches[0].clientX - touches[1].clientX;
    const dy = touches[0].clientY - touches[1].clientY;
    return Math.sqrt(dx * dx + dy * dy);
}

function zoomIn() {
    adjustZoom(0.1);
}

function zoomOut() {
    adjustZoom(-0.1);
}

function adjustZoom(delta) {
    const camera = document.getElementById('panoramaCamera');
    if (!camera) return;

    currentZoom = Math.max(0.5, Math.min(3, currentZoom + delta));
    const newFov = 75 / currentZoom;
    camera.setAttribute('fov', Math.max(25, Math.min(120, newFov)));
}

function switchPanorama(index) {
    if (index < 0 || index >= currentPanoramaImages.length) {
        console.error('Invalid panorama index:', index);
        return;
    }
    currentPanoramaIndex = index;
    loadPanorama(index);

    const navButtons = document.querySelectorAll('.panorama-nav-btn');
    navButtons.forEach((btn, i) => {
        btn.classList.toggle('active', i === index);
    });
}

function loadPanorama(index) {
    const sky = document.getElementById('panoramaSky');
    const loading = document.getElementById('panoramaLoading');

    if (!sky || !currentPanoramaImages[index]) {
        console.error('Sky element or image not found:', { sky: !!sky, image: currentPanoramaImages[index] });
        return;
    }
    const imagePath = window.phpVars.imageBasePath + currentPanoramaImages[index].trim();
    console.log('Loading panorama:', imagePath);

    loading.style.display = 'block';
    loading.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Loading 360° View...';
    const testImg = new Image();
    testImg.crossOrigin = 'anonymous';
    testImg.onload = () => {
        console.log('Panorama image loaded successfully:', imagePath);
        console.log('Image dimensions:', testImg.width, 'x', testImg.height);
        sky.setAttribute('src', imagePath);
        sky.setAttribute('geometry', {
            primitive: 'sphere',
            radius: 100,
            segmentsWidth: isMobile ? 48 : 64,
            segmentsHeight: isMobile ? 24 : 32
        });

        sky.setAttribute('material', {
            side: 'back',
            shader: 'standard',
            roughness: 1,
            metalness: 0,
            transparent: false,
            npot: true,
            src: imagePath
        });

        sky.setAttribute('scale', '-1 1 1');
        sky.setAttribute('rotation', '0 0 0');

        console.log('Panorama configured successfully');

        setTimeout(() => {
            loading.style.display = 'none';
        }, 500);
    };
    testImg.onerror = () => {
        console.error('Failed to load panorama image:', imagePath);
        loading.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i>Failed to load panorama image';
        setTimeout(() => {
            loading.style.display = 'none';
        }, 2000);
    };
    testImg.src = imagePath;
}

function close360Viewer() {
    const viewer = document.getElementById('panoramaViewer');
    if (viewer) {
        if (isFullscreen) {
            exitFullscreen();
        }

        viewer.style.display = 'none';
    }
    currentZoom = 1;
    const camera = document.getElementById('panoramaCamera');
    if (camera) {
        camera.setAttribute('fov', '75');
    }
    if (isMobile) {
        const viewerElement = document.getElementById('panoramaViewer');
        viewerElement.removeEventListener('touchstart', handleTouchStart);
        viewerElement.removeEventListener('touchmove', handleTouchMove);
        viewerElement.removeEventListener('touchend', handleTouchEnd);
    }
    panoramaViewerInitialized = false;
    console.log('360° viewer closed');
}

function resetView() {
    const camera = document.getElementById('panoramaCamera');
    if (camera) {
        camera.setAttribute('rotation', '0 0 0');
        camera.setAttribute('position', '0 0 0');

        currentZoom = 1;
        camera.setAttribute('fov', '75');

        console.log('View reset to default position');
    }
}

function toggleFullscreen() {
    const viewer = document.getElementById('panoramaViewer');

    if (!isFullscreen) {
        enterFullscreen(viewer);
    } else {
        exitFullscreen();
    }
}

function enterFullscreen(element) {
    const requestFullscreen = element.requestFullscreen ||
                             element.webkitRequestFullscreen ||
                             element.mozRequestFullScreen ||
                             element.msRequestFullscreen;
    if (requestFullscreen) {
        requestFullscreen.call(element).then(() => {
            isFullscreen = true;
            updateFullscreenButton();

            if (isMobile && screen.orientation && screen.orientation.lock) {
                screen.orientation.lock('landscape').catch(err => {
                    console.log('Orientation lock failed:', err);
                });
            }
        }).catch(err => {
            console.error('Fullscreen request failed:', err);
            alert('Fullscreen API is not supported or was denied.');
        });
    } else {
        alert('Fullscreen API is not supported on your browser.');
    }
}

function exitFullscreen() {
    const exitFullscreenFn = document.exitFullscreen ||
                            document.webkitExitFullscreen ||
                            document.mozCancelFullScreen ||
                            document.msExitFullscreen;

    if (exitFullscreenFn) {
        exitFullscreenFn.call(document).then(() => {
            isFullscreen = false;
            updateFullscreenButton();

            if (isMobile && screen.orientation && screen.orientation.unlock) {
                screen.orientation.unlock();
            }
        }).catch(err => {
            console.error('Exit fullscreen failed:', err);
        });
    }
}

function updateFullscreenButton() {
    const fullscreenTextSpan = document.getElementById('fullscreenText');
    const fullscreenBtn = document.getElementById('fullscreenBtn');

    if (fullscreenTextSpan && fullscreenBtn) {
        if (isFullscreen) {
            fullscreenTextSpan.textContent = 'Exit';
            fullscreenBtn.querySelector('i').className = 'fas fa-compress me-2';
        } else {
            fullscreenTextSpan.textContent = 'Fullscreen';
            fullscreenBtn.querySelector('i').className = 'fas fa-expand me-2';
        }
    }
}

document.addEventListener('fullscreenchange', () => {
    isFullscreen = !!document.fullscreenElement;
    updateFullscreenButton();
});

document.addEventListener('webkitfullscreenchange', () => {
    isFullscreen = !!document.webkitFullscreenElement;
    updateFullscreenButton();
});

document.addEventListener('mozfullscreenchange', () => {
    isFullscreen = !!document.mozFullScreenElement;
    updateFullscreenButton();
});

function setMainImage(index) {
    const carouselElement = document.getElementById('houseCarousel');
    if (carouselElement) {
        const carousel = bootstrap.Carousel.getInstance(carouselElement);
        if (carousel) {
            carousel.to(index);
        }
    }
}

// ========================================
// Page Initialization
// ========================================

document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('sidebar');
    const mainHeader = document.getElementById('mainHeader');
    const mainContent = document.getElementById('mainContent');
    const mobileMenuToggle = document.getElementById('mobileMenuToggle');
    const mobileOverlay = document.getElementById('mobileOverlay');
    
    function isMobileDevice() {
        return window.innerWidth <= 768;
    }
    
    function initializeLayout() {
        if (isMobileDevice()) {
            sidebar.style.left = '-280px';
            mainHeader.style.marginLeft = '0';
            mainHeader.style.width = '100%';
            mainContent.style.marginLeft = '0';
            mobileMenuToggle.style.display = 'flex';
        } else {
            sidebar.style.left = '0';
            mainHeader.style.marginLeft = '280px';
            mainHeader.style.width = 'calc(100% - 280px)';
            mainContent.style.marginLeft = '280px';
            mobileMenuToggle.style.display = 'none';
            mobileOverlay.style.display = 'none';
        }
    }
    
    initializeLayout();
    
    window.addEventListener('resize', function() {
        initializeLayout();
    });
    
    if (mobileMenuToggle) {
        mobileMenuToggle.addEventListener('click', function() {
            if (sidebar.style.left === '-280px' || sidebar.style.left === '') {
                sidebar.style.left = '0';
                mobileOverlay.style.display = 'block';
            } else {
                sidebar.style.left = '-280px';
                mobileOverlay.style.display = 'none';
            }
        });
    }
    
    if (mobileOverlay) {
        mobileOverlay.addEventListener('click', function() {
            sidebar.style.left = '-280px';
            mobileOverlay.style.display = 'none';
        });
    }
    
    const sidebarItems = document.querySelectorAll('.sidebar-item');
    sidebarItems.forEach(item => {
        item.addEventListener('click', function() {
            if (isMobileDevice()) {
                sidebar.style.left = '-280px';
                mobileOverlay.style.display = 'none';
            }
        });
    });

    loadRooms();
    initMap();
    console.log('Owner dashboard initialized');
    console.log('Device type:', isMobileDevice() ? 'Mobile' : 'Desktop');

    if (typeof window.AFRAME !== 'undefined') {
        console.log('A-Frame is loaded and ready');
    } else {
        console.error('A-Frame not loaded properly');
    }
});

window.open360Viewer = open360Viewer
window.close360Viewer = close360Viewer
window.resetView = resetView
window.zoomIn = zoomIn
window.zoomOut = zoomOut
window.toggleFullscreen = toggleFullscreen
window.switchPanorama = switchPanorama
