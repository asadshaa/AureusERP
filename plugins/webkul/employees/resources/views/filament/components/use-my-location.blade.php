<div
    x-data="{
        status: '',
        locate() {
            if (! window.isSecureContext || ! navigator.geolocation) {
                this.status = 'Location needs a secure (https) connection and a browser that supports it.'
                return
            }

            this.status = 'Requesting your location…'
            const onSuccess = (position) => {
                const scope = $root.closest('form') || document
                const fill = (field, value) => {
                    const input = scope.querySelector(`input[id$='.${field}']`)
                    if (input) {
                        input.value = value
                        input.dispatchEvent(new Event('input', { bubbles: true }))
                    }
                }
                fill('latitude', position.coords.latitude.toFixed(7))
                fill('longitude', position.coords.longitude.toFixed(7))
                this.status = `Filled from this device (accurate to about ${Math.round(position.coords.accuracy)} m). Stand at the centre of the workplace for best results.`
            }

            const onError = (error) => {
                if (error && error.code === 1) {
                    this.status = 'Location permission denied. Click the lock/settings icon in the browser address bar, set Location to Allow, and try again.'
                } else if (error && error.code === 2) {
                    this.status = 'Location unavailable on this device (common on desktops without GPS/Wi-Fi). Please enter the coordinates manually.'
                } else if (error && error.code === 3) {
                    this.status = 'Location request timed out. Please enter the coordinates manually.'
                } else {
                    this.status = 'Could not get your location. Allow location access for this site, or enter the coordinates by hand.'
                }
            }

            navigator.geolocation.getCurrentPosition(
                onSuccess,
                (error) => {
                    if (error && error.code !== 1) {
                        navigator.geolocation.getCurrentPosition(onSuccess, onError, { enableHighAccuracy: false, timeout: 10000, maximumAge: 60000 })
                    } else {
                        onError(error)
                    }
                },
                { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 },
            )
        },
    }"
    class="text-sm"
>
    <button type="button" x-on:click="locate()" class="fi-link font-medium underline">
        Use my current location
    </button>
    <span x-show="status" x-text="status" x-cloak class="ms-2"></span>
</div>
