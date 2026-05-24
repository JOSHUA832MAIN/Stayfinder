let mainMap
const allMarkers = []
const boardingHouses = window.STAYFINDER_DATA?.boardingHouses || []
const ustpPanaonCoords = window.STAYFINDER_DATA?.ustpCoords || [8.359995345948724, 123.84327331569628]
let searchTimeout
let lastSearchedLocation = null
const userLocationMarker = null
const userLocationPolyline = null
const selectedHouseMarker = null

// Add these variables for routing
let directionsService = null
let directionsRenderer = null
let currentRoute = null

const establishmentLocations = {
  USTP: { lat: 8.359995345948724, lng: 123.84327331569628 },
  Panaon: { lat: 8.348765, lng: 123.842104 },
  Downtown: { lat: 8.35, lng: 123.845 },
  Calinog: { lat: 8.36, lng: 123.84 },
}

function calculateDistance(lat1, lng1, lat2, lng2) {
  const R = 6371000
  const dLat = ((lat2 - lat1) * Math.PI) / 180
  const dLng = ((lng2 - lng1) * Math.PI) / 180
  const a =
    Math.sin(dLat / 2) * Math.sin(dLat / 2) +
    Math.cos((lat1 * Math.PI) / 180) * Math.cos((lat2 * Math.PI) / 180) * Math.sin(dLng / 2) * Math.sin(dLng / 2)
  const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a))
  return Math.round(R * c)
}

function searchByEstablishment(establishment) {
  console.log("[StayFinder] Searching by establishment:", establishment)

  const coords = establishmentLocations[establishment.name] || establishment.coordinates

  if (!coords) {
    console.error("[StayFinder] No coordinates found for:", establishment.name)
    return
  }

  const nearbyHouses = boardingHouses
    .map((house) => ({
      ...house,
      distanceToEstablishment: calculateDistance(house.map_lat, house.map_lng, coords.lat, coords.lng),
    }))
    .filter((house) => house.distanceToEstablishment <= 5000)
    .sort((a, b) => a.distanceToEstablishment - b.distanceToEstablishment)

  // Apply price filter if set
  const priceFiltered = filterByPrice(nearbyHouses)

  lastSearchedLocation = establishment.name
  showLocationResults(priceFiltered, establishment.name, [coords.lat, coords.lng])

  mainMap.setCenter(coords)
  mainMap.setZoom(15)
}

function searchBoardingHouses(query) {
  console.log("[StayFinder] Searching boarding houses:", query)

  if (!query || query.trim() === "" || query.trim().length < 2) {
    hideLocationResults()
    document.getElementById("searchResults").textContent = ""
    return
  }

  const lowerQuery = query.toLowerCase().trim()

  const filteredHouses = boardingHouses.filter(
    (house) =>
      (house.name && house.name.toLowerCase().includes(lowerQuery)) ||
      (house.purok && house.purok.toLowerCase().includes(lowerQuery)) ||
      (house.owner_address && house.owner_address.toLowerCase().includes(lowerQuery)),
  )

  // apply price filter
  const priceFiltered = filterByPrice(filteredHouses)

  if (priceFiltered.length > 0) {
    showLocationResults(priceFiltered, query)
    document.getElementById("searchResults").textContent =
      `Found ${priceFiltered.length} boarding houses for: "${query}"`
  } else {
    hideLocationResults()
    document.getElementById("searchResults").textContent = `No boarding houses found for: "${query}"`
  }
}

// Read single budget price input and return numeric value or null
function getPriceFilterValue() {
  const el = document.getElementById("priceInput")
  const val = el && el.value !== "" ? Number.parseFloat(el.value) : null
  return isNaN(val) ? null : val
}

// Filter houses by single budget value (max budget). If no filter set, return input houses unchanged.
function filterByPrice(houses) {
  const budget = getPriceFilterValue()
  if (budget === null) return houses

  return houses.filter((house) => {
    const hMin = house.min_price ? Number.parseFloat(house.min_price) : null
    const hMax = house.max_price ? Number.parseFloat(house.max_price) : null

    // Exclude houses with no price info when a budget is set
    if (hMin === null && hMax === null) return false

    // Use lowest known price for the house to check affordability
    const houseLow = hMin !== null ? hMin : hMax
    return houseLow !== null && houseLow <= budget
  })
}
function showLocationResults(houses, searchLocation, referenceCoords = ustpPanaonCoords, showCoordinates = false) {
  const resultsSection = document.getElementById("locationResultsSection")
  const resultsTitle = document.getElementById("locationResultsTitle")
  const locationBadge = document.getElementById("locationBadge")
  const nearbyGrid = document.getElementById("nearbyHousesGrid")

  if (resultsSection) {
    resultsSection.style.display = "block"
    resultsSection.style.visibility = "visible"
    resultsSection.style.opacity = "1"
    resultsSection.style.height = "auto"
    resultsSection.style.overflow = "visible"
    resultsSection.style.position = "static"
    resultsSection.style.left = "auto"
    resultsSection.classList.remove("d-none")
    resultsSection.removeAttribute("hidden")
  }

  resultsTitle.textContent = `Boarding Houses near ${searchLocation}`
  locationBadge.textContent = `${houses.length} found`

  nearbyGrid.innerHTML = ""

  houses.forEach((house) => {
    const images = house.images ? house.images.split(",") : []
    const firstImage = images.length > 0 ? images[0].trim() : "img/default.jpg"

    let distanceText = ""
    let distance = 0
    if (house.map_lat && house.map_lng) {
      distance =
        house.distanceToEstablishment ||
        house.distanceToUser ||
        calculateDistance(
          Number.parseFloat(house.map_lat),
          Number.parseFloat(house.map_lng),
          referenceCoords[0],
          referenceCoords[1],
        )

      if (distance < 1000) {
        distanceText = `${distance}m from ${searchLocation}`
      } else {
        distanceText = `${(distance / 1000).toFixed(1)}km from ${searchLocation}`
      }
    }

    let priceDisplay = ""
    if (house.min_price && house.max_price) {
      const minPrice = Number.parseFloat(house.min_price)
      const maxPrice = Number.parseFloat(house.max_price)

      if (minPrice > 0 && maxPrice > 0) {
        if (minPrice === maxPrice) {
          priceDisplay = `₱${minPrice.toLocaleString()}`
        } else {
          priceDisplay = `₱${minPrice.toLocaleString()} - ₱${maxPrice.toLocaleString()}`
        }
      } else {
        priceDisplay = "Contact Owner"
      }
    } else {
      priceDisplay = "Contact Owner"
    }

    const ownerName = house.owner_fullname ? house.owner_fullname : "Owner"
    const ownerContact = house.owner_contact ? house.owner_contact : ""
    const ownerEmail = house.owner_email ? house.owner_email : ""
    const amenities = house.all_amenities ? house.all_amenities : "No amenities listed"
    const locationDisplay = house.owner_address ? house.owner_address : house.purok || "Location available upon contact"

    const houseCard = `
      <div class="nearby-house-card">
        <div class="nearby-card-image" style="background-image: url('${firstImage}');" onerror="this.style.backgroundImage='url(img/default.jpg)'">
          <div class="nearby-price-badge">${priceDisplay}</div>
          ${distanceText ? `<div class="nearby-distance-badge">${distanceText}</div>` : ""}
        </div>
        <div class="p-3">
          <div class="mb-2 pb-2" style="border-bottom: 1px solid #e0e0e0;">
            <div class="small mb-1">
              <strong style="color: #333;">👤 ${ownerName}</strong>
            </div>
            <div class="small text-muted mb-1">
              ${ownerContact ? `<div><i class="fas fa-phone me-1"></i>${ownerContact}</div>` : ""}
              ${ownerEmail ? `<div><i class="fas fa-envelope me-1"></i>${ownerEmail}</div>` : ""}
            </div>
            <div class="small text-muted">
              <i class="fas fa-star me-1" style="color: #ffc107;"></i>
              <strong>Amenities:</strong> ${amenities}
            </div>
          </div>
          
          <h6 class="fw-bold mb-2">${house.name.toUpperCase()}</h6>
          
          <p class="text-muted small mb-2" style="font-size: 0.8rem;">
            <i class="fas fa-map-marker-alt me-1"></i>
            ${locationDisplay}
          </p>
          
          <p class="text-muted small mb-3" style="font-size: 0.8rem; line-height: 1.3;">
            ${house.description ? house.description.substring(0, 80) + "..." : "Quality boarding house with modern amenities"}
          </p>
          
          <div class="mt-2 d-flex gap-2 flex-wrap">
            <a href="bookform/book_house.php?house_id=${house.id}" role="button" class="btn btn-sm fw-bold flex-grow-1" style="background: linear-gradient(135deg,#f4c430,#e8b923); color:#fff; text-decoration:none; display:inline-flex; align-items:center; justify-content:center;">
              <i class="fas fa-check-circle me-1"></i> Check Availability
            </a>
            <a href="accommodationoverview/view_house_details.php?id=${house.id}" class="btn-sm text-white fw-bold flex-grow-1" style="background: linear-gradient(135deg,#f4c430,#e8b923); color:#fff; display:inline-flex; align-items:center; justify-content:center;">
              <i class="fas fa-eye me-1"></i> View Details
            </a>
          </div>
        </div>
      </div>
    `

    nearbyGrid.innerHTML += houseCard
  })

  resultsSection.style.display = "block"

  const scrollDelay = isMobileDevice() ? 100 : 300
  setTimeout(() => {
    resultsSection.scrollIntoView({ behavior: "smooth", block: "start" })
  }, scrollDelay)
}

function hideLocationResults() {
  const resultsSection = document.getElementById("locationResultsSection")
  if (resultsSection) {
    resultsSection.style.display = "none"
    resultsSection.classList.add("d-none")
  }
}

function calculateAndDisplayRoute(start, destination, house) {
  if (!window.google) {
    console.error("Google Maps API is not loaded")
    return
  }

  if (!directionsService) {
    directionsService = new window.google.maps.DirectionsService()
  }
  if (!directionsRenderer) {
    directionsRenderer = new window.google.maps.DirectionsRenderer({
      map: mainMap,
      suppressMarkers: true, // This removes A and B markers
      suppressInfoWindows: true, // Suppress info windows on markers
      polylineOptions: {
        strokeColor: "#4285f4", // Changed to blue
        strokeOpacity: 0.8,
        strokeWeight: 6,
      },
      preserveViewport: false,
    })
  } else {
    directionsRenderer.setMap(mainMap)
    directionsRenderer.setOptions({ suppressMarkers: true, suppressInfoWindows: true })
  }

  const request = {
    origin: start,
    destination: destination,
    travelMode: window.google.maps.TravelMode.DRIVING,
  }

  directionsService.route(request, (result, status) => {
    if (status == "OK") {
      directionsRenderer.setDirections(result)
      currentRoute = result

      // Add custom markers for start and end points
      if (window.routeStartMarker) {
        window.routeStartMarker.setMap(null)
      }
      if (window.routeEndMarker) {
        window.routeEndMarker.setMap(null)
      }

      // Blue dot for start (user location)
      window.routeStartMarker = new window.google.maps.Marker({
        position: start,
        map: mainMap,
        icon: {
          path: window.google.maps.SymbolPath.CIRCLE,
          scale: 8,
          fillColor: "#4285f4",
          fillOpacity: 1,
          strokeColor: "#ffffff",
          strokeWeight: 2,
        },
        title: "Your Location",
      })

      // House marker for destination
      window.routeEndMarker = new window.google.maps.Marker({
        position: destination,
        map: mainMap,
        icon: {
          url: "img/icons/house_9408891.png",
          scaledSize: new window.google.maps.Size(40, 40),
          anchor: new window.google.maps.Point(20, 40),
        },
        title: house.name,
      })

      // Show route info
      const route = result.routes[0]
      const leg = route.legs[0]

      const routeContainer = document.getElementById("routeInfoBoxContainer")
      if (routeContainer) {
        routeContainer.innerHTML = `
          <div class="route-info-box">
            <div class="route-info-header">
              <div><strong>📍 Route to ${house.name}</strong></div>
              <button class="route-close-btn" onclick="clearRoute()">✕</button>
            </div>
            <div class="route-info-content">
              <div class="route-info-item">
                <i class="fas fa-route" style="color: #4285f4;"></i>
                <span><strong>Distance:</strong> ${leg.distance.text}</span>
              </div>
              <div class="route-info-item">
                <i class="fas fa-clock" style="color: #4285f4;"></i>
                <span><strong>Duration:</strong> ${leg.duration.text}</span>
              </div>
              <div class="route-info-item">
                <i class="fas fa-map-marker-alt" style="color: #4285f4;"></i>
                <span>${house.owner_address || house.purok || "Location"}</span>
              </div>
              <div class="route-info-item">
                <i class="fas fa-phone" style="color: #4285f4;"></i>
                <span>${house.owner_contact || "Contact Owner"}</span>
              </div>
            </div>
          </div>
        `
        routeContainer.classList.add("active")
        routeContainer.style.display = "block"
      }
    } else {
      console.error("Directions request failed due to " + status)
      // Fallback: just center on destination
      mainMap.setCenter(destination)
      mainMap.setZoom(17)

      const routeContainer = document.getElementById("routeInfoBoxContainer")
      if (routeContainer) {
        routeContainer.innerHTML = `
          <div class="route-info-box">
            <div class="route-info-header">
              <div><strong>📍 ${house.name}</strong></div>
              <button class="route-close-btn" onclick="clearRoute()">✕</button>
            </div>
            <div class="route-info-content">
              <div class="route-info-item">
                <i class="fas fa-info-circle" style="color: #4285f4;"></i>
                <span>Route calculation failed. Map centered on location.</span>
              </div>
              <div class="route-info-item">
                <i class="fas fa-map-marker-alt" style="color: #4285f4;"></i>
                <span>${house.owner_address || house.purok || "Location"}</span>
              </div>
              <div class="route-info-item">
                <i class="fas fa-phone" style="color: #4285f4;"></i>
                <span>${house.owner_contact || "Contact Owner"}</span>
              </div>
            </div>
          </div>
        `
        routeContainer.classList.add("active")
        routeContainer.style.display = "block"
      }
    }
  })
}

// UPDATED: Show Route function with actual routing
function showRouteToHouse(houseId) {
  console.log("📍 Show Route called for house ID:", houseId)

  const house = boardingHouses.find((h) => h.id === houseId)
  console.log("🏠 Found house:", house)

  if (!house) {
    alert("House not found in database")
    return
  }

  // Check if house has coordinates
  if (!house.map_lat || !house.map_lng) {
    alert("House location coordinates not available. Please contact the owner for directions.")
    return
  }

  const lat = Number.parseFloat(house.map_lat)
  const lng = Number.parseFloat(house.map_lng)

  if (isNaN(lat) || isNaN(lng)) {
    alert("Invalid house coordinates. Please contact the owner for directions.")
    return
  }

  const destination = { lat: lat, lng: lng }
  console.log("🎯 Destination coordinates:", destination)

  // Get user's current location or use a default starting point
  if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(
      (position) => {
        const userLocation = {
          lat: position.coords.latitude,
          lng: position.coords.longitude,
        }
        console.log("👤 User location:", userLocation)
        calculateAndDisplayRoute(userLocation, destination, house)
      },
      (error) => {
        console.log("Geolocation failed, using default location:", error)
        // Use USTP as default starting point if geolocation fails
        const defaultStart = { lat: 8.359995345948724, lng: 123.84327331569628 }
        calculateAndDisplayRoute(defaultStart, destination, house)
      },
      {
        enableHighAccuracy: true,
        timeout: 10000,
        maximumAge: 60000,
      },
    )
  } else {
    // Geolocation not supported, use default starting point
    const defaultStart = { lat: 8.359995345948724, lng: 123.84327331569628 }
    calculateAndDisplayRoute(defaultStart, destination, house)
  }
}

function clearRoute() {
  const routeContainer = document.getElementById("routeInfoBoxContainer")
  if (routeContainer) {
    routeContainer.innerHTML = ""
    routeContainer.classList.remove("active")
    routeContainer.style.display = "none"
  }

  // Clear the directions from the map
  if (directionsRenderer) {
    directionsRenderer.setDirections({ routes: [] })
    directionsRenderer.setMap(null)
  }

  // Remove custom route markers
  if (window.routeStartMarker) {
    window.routeStartMarker.setMap(null)
    window.routeStartMarker = null
  }
  if (window.routeEndMarker) {
    window.routeEndMarker.setMap(null)
    window.routeEndMarker = null
  }
  if (window.accuracyCircle) {
    window.accuracyCircle.setMap(null)
    window.accuracyCircle = null
  }

  currentRoute = null
}
function isMobileDevice() {
  return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent)
}

function useMyLocation() {
  const button = document.getElementById("useMyLocationButton")
  const originalText = button.innerHTML

  button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Getting Location...'
  button.disabled = true

  if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(
      (position) => {
        const lat = position.coords.latitude
        const lng = position.coords.longitude
        const accuracy = position.coords.accuracy

        console.log("📍 User location found:", { lat, lng, accuracy: accuracy + " meters" })

        // Clear existing user location marker
        if (window.userLocationMarker) {
          window.userLocationMarker.setMap(null)
        }

        // Add blue dot for user location with better styling
        window.userLocationMarker = new window.google.maps.Marker({
          position: { lat, lng },
          map: mainMap,
          icon: {
            path: window.google.maps.SymbolPath.CIRCLE,
            scale: 10, // Slightly larger for better visibility
            fillColor: "#4285f4", // Blue color
            fillOpacity: 1,
            strokeColor: "#ffffff",
            strokeWeight: 3,
          },
          title: `Your Current Location (Accuracy: ${Math.round(accuracy)}m)`,
          zIndex: 1000,
        })

        // Add accuracy circle
        if (window.accuracyCircle) {
          window.accuracyCircle.setMap(null)
        }

        window.accuracyCircle = new window.google.maps.Circle({
          strokeColor: "#4285f4",
          strokeOpacity: 0.3,
          strokeWeight: 1,
          fillColor: "#4285f4",
          fillOpacity: 0.1,
          map: mainMap,
          center: { lat, lng },
          radius: accuracy, // Circle radius based on accuracy
        })

        // Center map on user location with appropriate zoom
        mainMap.setCenter({ lat, lng })
        mainMap.setZoom(16) // Higher zoom for better precision

        // Show nearby houses
        const nearbyHouses = boardingHouses
          .map((house) => ({
            ...house,
            distanceToUser: calculateDistance(lat, lng, house.map_lat, house.map_lng),
          }))
          .filter((house) => house.distanceToUser <= 5000)
          .sort((a, b) => a.distanceToUser - b.distanceToUser)

        showLocationResults(nearbyHouses, "Your Location", [lat, lng])

        // Reset button
        button.innerHTML = '<i class="fas fa-crosshairs"></i> Use My Location'
        button.disabled = false
      },
      (error) => {
        console.error("Geolocation error:", error)
        let errorMessage = "Unable to get your location. "

        switch (error.code) {
          case error.PERMISSION_DENIED:
            errorMessage += "Please allow location access in your browser settings."
            break
          case error.POSITION_UNAVAILABLE:
            errorMessage += "Location information is unavailable."
            break
          case error.TIMEOUT:
            errorMessage += "Location request timed out. Please try again."
            break
          default:
            errorMessage += "Please check your location permissions."
        }

        alert(errorMessage)

        // Reset button
        button.innerHTML = '<i class="fas fa-crosshairs"></i> Use My Location'
        button.disabled = false
      },
      {
        enableHighAccuracy: true, // Force high accuracy
        timeout: 15000, // Increased timeout
        maximumAge: 30000, // Don't use cached position older than 30 seconds
      },
    )
  } else {
    alert("Geolocation not supported by your browser")
    button.innerHTML = '<i class="fas fa-crosshairs"></i> Use My Location'
    button.disabled = false
  }
}
function addUserLocationMarker(lat, lng) {
  // Remove existing user marker if any
  if (window.userLocationMarker) {
    window.userLocationMarker.setMap(null)
  }

  window.userLocationMarker = new window.google.maps.Marker({
    position: { lat, lng },
    map: mainMap,
    icon: {
      path: window.google.maps.SymbolPath.CIRCLE,
      scale: 10,
      fillColor: "#4A90E2",
      fillOpacity: 1,
      strokeColor: "#FFFFFF",
      strokeWeight: 3,
    },
    title: "Your Current Location",
    zIndex: 1000,
  })
}
function toggleMapFullscreen() {
  const map = document.getElementById("mainMap")
  const html = document.documentElement
  const body = document.body

  if (!document.fullscreenElement) {
    map.classList.add("fullscreen-map")
    html.classList.add("map-fullscreen-active")
    body.classList.add("map-fullscreen-active")

    if (map.requestFullscreen) {
      map.requestFullscreen().catch((err) => console.log(err))
    }
  } else {
    map.classList.remove("fullscreen-map")
    html.classList.remove("map-fullscreen-active")
    body.classList.remove("map-fullscreen-active")

    if (document.exitFullscreen) {
      document.exitFullscreen()
    }
  }

  setTimeout(() => {
    if (mainMap) {
      mainMap.invalidateSize()
    }
  }, 100)
}

function initMainMap() {
  console.log("🚀 Starting Google Maps initialization...")

  const defaultCenter = { lat: 8.359995345948724, lng: 123.84327331569628 }

  mainMap = new window.google.maps.Map(document.getElementById("mainMap"), {
    center: defaultCenter,
    zoom: 13,
    mapTypeId: window.google.maps.MapTypeId.HYBRID,
    gestureHandling: "greedy",
    mapTypeControl: true,
    mapTypeControlOptions: {
      style: window.google.maps.MapTypeControlStyle.HORIZONTAL_BAR,
      position: window.google.maps.ControlPosition.TOP_CENTER,
    },
    zoomControl: true,
    zoomControlOptions: {
      position: window.google.maps.ControlPosition.RIGHT_CENTER,
    },
    streetViewControl: true,
    streetViewControlOptions: {
      position: window.google.maps.ControlPosition.RIGHT_BOTTOM,
    },
    fullscreenControl: true,
    fullscreenControlOptions: {
      position: window.google.maps.ControlPosition.RIGHT_TOP,
    },
  })

  // Initialize directions service
  directionsService = new window.google.maps.DirectionsService()
  directionsRenderer = new window.google.maps.DirectionsRenderer({
    map: null, // Start with no map
    suppressMarkers: false,
    polylineOptions: {
      strokeColor: "#4a90e2",
      strokeOpacity: 0.8,
      strokeWeight: 6,
    },
  })

  addSearchBox()
  addBoardingHouseMarkers()

  console.log("✅ Google Maps initialization complete")
}

function addSearchBox() {
  const input = document.getElementById("searchInput")
  const searchBox = new window.google.maps.places.SearchBox(input)

  mainMap.addListener("bounds_changed", () => {
    searchBox.setBounds(mainMap.getBounds())
  })

  searchBox.addListener("places_changed", () => {
    const places = searchBox.getPlaces()
    if (places.length === 0) return

    const place = places[0]
    if (!place.geometry) return

    if (place.geometry.viewport) {
      mainMap.fitBounds(place.geometry.viewport)
    } else {
      mainMap.setCenter(place.geometry.location)
      mainMap.setZoom(17)
    }

    searchByEstablishment({
      name: place.name,
      coordinates: [place.geometry.location.lat(), place.geometry.location.lng()],
      type: place.types[0] || "location",
      icon: "📍",
    })
  })
}

function addBoardingHouseMarkers() {
  console.log("🏠 Adding boarding house markers to Google Map...")

  let markersAdded = 0
  boardingHouses.forEach((house, index) => {
    if (house.map_lat && house.map_lng) {
      const lat = Number.parseFloat(house.map_lat)
      const lng = Number.parseFloat(house.map_lng)

      if (isNaN(lat) || isNaN(lng)) {
        console.error("❌ Invalid coordinates for boarding house:", house.name)
        return
      }

      const houseMarker = new window.google.maps.Marker({
        position: { lat: lat, lng: lng },
        map: mainMap,
        icon: {
          url: "img/icons/house_9408891.png",
          scaledSize: new window.google.maps.Size(40, 40),
          anchor: new window.google.maps.Point(20, 40),
        },
        title: house.name,
      })

      allMarkers.push({
        marker: houseMarker,
        house: house,
      })

      markersAdded++

      const images = house.images ? house.images.split(",") : []
      const firstImage = images.length > 0 ? images[0].trim() : "img/default.jpg"

      let popupPriceDisplay = ""
      if (house.min_price && house.max_price) {
        const minPrice = Number.parseFloat(house.min_price)
        const maxPrice = Number.parseFloat(house.max_price)

        if (minPrice > 0 && maxPrice > 0) {
          if (minPrice === maxPrice) {
            popupPriceDisplay = `💰 ₱${minPrice.toLocaleString()}`
          } else {
            popupPriceDisplay = `💰 ₱${minPrice.toLocaleString()} - ₱${maxPrice.toLocaleString()}`
          }
        } else {
          popupPriceDisplay = `💰 Contact Owner for Price`
        }
      } else {
        popupPriceDisplay = `💰 Contact Owner for Price`
      }

      const popupLocationDisplay = house.full_location
        ? house.full_location
        : house.purok || house.owner_address || "Contact for exact location"

      const infoWindowContent = `
        <div class="custom-popup">
          <div class="popup-header">
            <i class="fas fa-home" style="margin-right: 8px;"></i>
            ${house.name}
          </div>
          <div class="popup-content">
            <img src="${firstImage}" alt="${house.name}" style="width: 100%; height: 120px; object-fit: cover; border-radius: 8px; margin-bottom: 10px; border: 2px solid #ddd;" onerror="this.src='img/default.jpg'">
            <div class="popup-price">${popupPriceDisplay}</div>
            <div style="font-size: 11px; margin-top: 6px;">
              ${
                house.owner_email || house.owner_contact
                  ? `${house.owner_email ? `<div><i class="fas fa-envelope"></i> <strong>Email:</strong> <a href="mailto:${house.owner_email}">${house.owner_email}</a></div>` : ""}${house.owner_contact ? `<div><i class="fas fa-phone"></i> <strong>Phone:</strong> <a href="tel:${house.owner_contact}">${house.owner_contact}</a></div>` : ""}`
                  : `<div style="color:#888; font-style:italic;">Owner contact details not available.</div>`
              }
            </div>
            <div style="background: #fff3cd; padding: 6px; border-radius: 4px; margin: 6px 0; font-size: 10px;">
              <p style="margin: 1px 0;"><strong>📍 Address:</strong> ${popupLocationDisplay}</p>
            </div>
            <div style="text-align: center; margin-top: 6px; display: flex; gap: 5px;">
              <a href="bookform/book_house.php?house_id=${house.id}" class="btn-custom" style="background: linear-gradient(135deg,#f4c430,#e8b923); color: #fff; flex: 1; display:inline-flex; align-items:center; justify-content:center;">
                <i class="fas fa-check-circle"></i> Check Availability
              </a>
              <a href="accommodationoverview/view_house_details.php?id=${house.id}" class="btn-custom btn-secondary-custom" style="background: linear-gradient(135deg,#f4c430,#e8b923); color: #fff; flex: 1; display:inline-flex; align-items:center; justify-content:center;">
                <i class="fas fa-eye"></i> View Details
              </a>
            </div>
          </div>
        </div>
      `

      const infoWindow = new window.google.maps.InfoWindow({
        content: infoWindowContent,
        maxWidth: 280,
      })

      houseMarker.addListener("click", () => {
        infoWindow.open(mainMap, houseMarker)
      })
    }
  })

  console.log(`✅ Total markers added: ${markersAdded} boarding houses`)

  if (markersAdded > 0) {
    const bounds = new window.google.maps.LatLngBounds()
    bounds.extend({ lat: 8.359995345948724, lng: 123.84327331569628 })

    allMarkers.forEach((markerData) => {
      bounds.extend(markerData.marker.getPosition())
    })

    mainMap.fitBounds(bounds)
  } else {
    mainMap.setCenter({ lat: 8.359995345948724, lng: 123.84327331569628 })
    mainMap.setZoom(13)
  }
}

document.addEventListener("DOMContentLoaded", () => {
  console.log("🚀 DOM loaded, initializing map...")

  setTimeout(() => {
    const mapContainer = document.getElementById("mainMap")
    if (mapContainer) {
      console.log("✅ Map container found, initializing...")
      initMainMap()
    } else {
      console.error("❌ Map container not found!")
    }
  }, 500)

  const searchInput = document.getElementById("searchInput")
  const clearButton = document.getElementById("clearSearch")

  // Improved search with debouncing
  let searchTimeout
  let lastSearchValue = ""

  searchInput.addEventListener("input", function () {
    const currentValue = this.value.trim()
    // Do not auto-search on partial input. Require Enter key or place selection.
    if (currentValue === "") {
      hideLocationResults()
      clearRoute()
      document.getElementById("searchResults").textContent = ""
    } else {
      // show hint to user to press Enter for full search
      const sr = document.getElementById("searchResults")
      if (sr) sr.textContent = "Type full location and press Enter to search"
    }
  })

  searchInput.addEventListener("keyup", function (e) {
    if (e.key === "Enter") {
      clearTimeout(searchTimeout)
      const currentValue = this.value.trim()
      if (currentValue.length >= 2) {
        searchBoardingHouses(currentValue)
      }
    }
  })

  clearButton.addEventListener("click", () => {
    searchInput.value = ""
    lastSearchValue = ""
    hideLocationResults()
    clearRoute()
    document.getElementById("searchResults").textContent = ""
    const priceEl = document.getElementById("priceInput")
    if (priceEl) priceEl.value = ""

  })

  // Price filter apply button and input change handling (single price input)
  const applyPriceBtn = document.getElementById("applyPriceFilter")
  const priceEl = document.getElementById("priceInput")

  function reapplyCurrentSearch() {
    const q = (searchInput && searchInput.value) ? searchInput.value.trim() : ""
    if (q && q.length >= 2) {
      searchBoardingHouses(q)
      return
    }

    if (lastSearchedLocation && establishmentLocations && establishmentLocations[lastSearchedLocation]) {
      searchByEstablishment({ name: lastSearchedLocation, coordinates: establishmentLocations[lastSearchedLocation] })
      return
    }

    const allFiltered = filterByPrice(boardingHouses)
    if (allFiltered.length > 0) showLocationResults(allFiltered, "this area")
    else hideLocationResults()
  }

  if (applyPriceBtn) applyPriceBtn.addEventListener("click", reapplyCurrentSearch)
  if (priceEl) priceEl.addEventListener("keyup", (e) => { if (e.key === "Enter") reapplyCurrentSearch() })
})

window.addEventListener("resize", () => {
  if (mainMap) {
    setTimeout(() => {
      mainMap.invalidateSize()
      console.log("🔄 Map resized for responsive layout")
    }, 100)
  }
})

window.addEventListener("orientationchange", () => {
  if (mainMap) {
    setTimeout(() => {
      mainMap.invalidateSize()
      console.log("🔄 Map resized after orientation change")
    }, 500)
  }
})
