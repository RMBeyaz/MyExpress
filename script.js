const header = document.querySelector('[data-header]');
const menuToggle = document.querySelector('[data-menu-toggle]');
const deliveryForm = document.querySelector('[data-home-form]');
const addressInputs = document.querySelectorAll('[data-address-input]');
const priceEstimate = document.querySelector('[data-price-estimate]');
const detailPriceEstimate = document.querySelector('[data-detail-price-estimate]');
const requestSummary = document.querySelector('[data-request-summary]');
const detailForm = document.querySelector('[data-detail-form]');
const scheduleFields = document.querySelector('[data-schedule-fields]');
const scheduleDateField = document.querySelector('[data-schedule-date]');
const scheduleTimeFields = document.querySelectorAll('[data-schedule-time]');
const addressSearchTimers = new WeakMap();

const istanbulDistricts = [
  { name: 'Adalar', lat: 40.876, lng: 29.091 },
  { name: 'Arnavutköy', lat: 41.186, lng: 28.739 },
  { name: 'Ataşehir', lat: 40.984, lng: 29.127 },
  { name: 'Avcılar', lat: 40.979, lng: 28.721 },
  { name: 'Bağcılar', lat: 41.034, lng: 28.857 },
  { name: 'Bahçelievler', lat: 40.997, lng: 28.850 },
  { name: 'Bakırköy', lat: 40.980, lng: 28.873 },
  { name: 'Başakşehir', lat: 41.093, lng: 28.802 },
  { name: 'Bayrampaşa', lat: 41.046, lng: 28.902 },
  { name: 'Beşiktaş', lat: 41.043, lng: 29.008 },
  { name: 'Beykoz', lat: 41.123, lng: 29.108 },
  { name: 'Beylikdüzü', lat: 41.001, lng: 28.641 },
  { name: 'Beyoğlu', lat: 41.037, lng: 28.977 },
  { name: 'Büyükçekmece', lat: 41.020, lng: 28.585 },
  { name: 'Çatalca', lat: 41.143, lng: 28.461 },
  { name: 'Çekmeköy', lat: 41.033, lng: 29.178 },
  { name: 'Esenler', lat: 41.043, lng: 28.879 },
  { name: 'Esenyurt', lat: 41.034, lng: 28.680 },
  { name: 'Eyüpsultan', lat: 41.047, lng: 28.933 },
  { name: 'Fatih', lat: 41.018, lng: 28.940 },
  { name: 'Gaziosmanpaşa', lat: 41.058, lng: 28.915 },
  { name: 'Güngören', lat: 41.017, lng: 28.872 },
  { name: 'Kadıköy', lat: 40.991, lng: 29.027 },
  { name: 'Kağıthane', lat: 41.079, lng: 28.973 },
  { name: 'Kartal', lat: 40.899, lng: 29.191 },
  { name: 'Küçükçekmece', lat: 41.000, lng: 28.789 },
  { name: 'Maltepe', lat: 40.936, lng: 29.155 },
  { name: 'Pendik', lat: 40.879, lng: 29.258 },
  { name: 'Sancaktepe', lat: 41.003, lng: 29.231 },
  { name: 'Sarıyer', lat: 41.166, lng: 29.057 },
  { name: 'Silivri', lat: 41.073, lng: 28.247 },
  { name: 'Sultanbeyli', lat: 40.968, lng: 29.261 },
  { name: 'Sultangazi', lat: 41.106, lng: 28.868 },
  { name: 'Şile', lat: 41.176, lng: 29.613 },
  { name: 'Şişli', lat: 41.061, lng: 28.987 },
  { name: 'Tuzla', lat: 40.817, lng: 29.300 },
  { name: 'Ümraniye', lat: 41.025, lng: 29.096 },
  { name: 'Üsküdar', lat: 41.023, lng: 29.015 },
  { name: 'Zeytinburnu', lat: 40.994, lng: 28.904 },
];

const addressLocations = [
  ...istanbulDistricts.map((district) => ({
    ...district,
    display: district.name,
    search: `${district.name} ilçe istanbul`,
    type: 'İlçe',
  })),
  { display: 'Nispetiye Caddesi, Etiler, Beşiktaş', search: 'nispetiye caddesi etiler beşiktaş', lat: 41.079, lng: 29.034, type: 'Cadde' },
  { display: 'Bağdat Caddesi, Kadıköy', search: 'bagdat caddesi kadikoy caddebostan suadiye', lat: 40.965, lng: 29.075, type: 'Cadde' },
  { display: 'İstiklal Caddesi, Beyoğlu', search: 'istiklal caddesi beyoglu taksim', lat: 41.034, lng: 28.978, type: 'Cadde' },
  { display: 'Abdi İpekçi Caddesi, Nişantaşı, Şişli', search: 'abdi ipekci caddesi nisantasi sisli', lat: 41.050, lng: 28.992, type: 'Cadde' },
  { display: 'Valikonağı Caddesi, Nişantaşı, Şişli', search: 'valikonagi caddesi nisantasi sisli', lat: 41.050, lng: 28.994, type: 'Cadde' },
  { display: 'Büyükdere Caddesi, Şişli', search: 'buyukdere caddesi sisli mecidiyekoy levent', lat: 41.070, lng: 29.010, type: 'Cadde' },
  { display: 'Barbaros Bulvarı, Beşiktaş', search: 'barbaros bulvari besiktas', lat: 41.047, lng: 29.008, type: 'Bulvar' },
  { display: 'Vatan Caddesi, Fatih', search: 'vatan caddesi fatih adnan menderes bulvari', lat: 41.018, lng: 28.929, type: 'Cadde' },
  { display: 'Millet Caddesi, Fatih', search: 'millet caddesi fatih findikzade', lat: 41.012, lng: 28.934, type: 'Cadde' },
  { display: 'Rıhtım Caddesi, Kadıköy', search: 'rihtim caddesi kadikoy', lat: 40.993, lng: 29.024, type: 'Cadde' },
  { display: 'Minibüs Caddesi, Kadıköy', search: 'minibus caddesi kadikoy goztepe bostanci', lat: 40.978, lng: 29.077, type: 'Cadde' },
  { display: 'Alemdağ Caddesi, Ümraniye', search: 'alemdag caddesi umraniye', lat: 41.025, lng: 29.101, type: 'Cadde' },
  { display: 'İnönü Caddesi, Ataşehir', search: 'inonu caddesi atasehir', lat: 40.984, lng: 29.127, type: 'Cadde' },
  { display: 'Koşuyolu Mahallesi, Kadıköy', search: 'kosuyolu mahallesi kadikoy', lat: 41.006, lng: 29.037, type: 'Mahalle' },
  { display: 'Caddebostan Mahallesi, Kadıköy', search: 'caddebostan mahallesi kadikoy bagdat caddesi', lat: 40.967, lng: 29.061, type: 'Mahalle' },
  { display: 'Suadiye Mahallesi, Kadıköy', search: 'suadiye mahallesi kadikoy bagdat caddesi', lat: 40.960, lng: 29.083, type: 'Mahalle' },
  { display: 'Moda Mahallesi, Kadıköy', search: 'moda mahallesi kadikoy', lat: 40.986, lng: 29.025, type: 'Mahalle' },
  { display: 'Etiler Mahallesi, Beşiktaş', search: 'etiler mahallesi besiktas nispetiye', lat: 41.085, lng: 29.035, type: 'Mahalle' },
  { display: 'Levent Mahallesi, Beşiktaş', search: 'levent mahallesi besiktas buyukdere', lat: 41.079, lng: 29.013, type: 'Mahalle' },
  { display: 'Nişantaşı, Şişli', search: 'nisantasi tesvikiye sisli valikonagi abdi ipekci', lat: 41.050, lng: 28.993, type: 'Semt' },
  { display: 'Mecidiyeköy Mahallesi, Şişli', search: 'mecidiyekoy mahallesi sisli buyukdere', lat: 41.067, lng: 28.999, type: 'Mahalle' },
  { display: 'Maslak Mahallesi, Sarıyer', search: 'maslak mahallesi sariyer buyukdere', lat: 41.111, lng: 29.021, type: 'Mahalle' },
  { display: 'Taksim, Beyoğlu', search: 'taksim meydan beyoglu istiklal', lat: 41.037, lng: 28.985, type: 'Semt' },
  { display: 'Karaköy, Beyoğlu', search: 'karakoy beyoglu kemankes', lat: 41.024, lng: 28.974, type: 'Semt' },
  { display: 'Laleli, Fatih', search: 'laleli fatih ordu caddesi', lat: 41.010, lng: 28.954, type: 'Semt' },
  { display: 'Merter, Güngören', search: 'merter gungoren tekstil merkezi', lat: 41.007, lng: 28.890, type: 'Semt' },
  { display: 'İkitelli OSB, Başakşehir', search: 'ikitelli osb basaksehir organize sanayi', lat: 41.075, lng: 28.786, type: 'Bölge' },
  { display: 'Perpa Ticaret Merkezi, Şişli', search: 'perpa ticaret merkezi sisli okmeydani', lat: 41.064, lng: 28.967, type: 'İş merkezi' },
  { display: 'İstoç, Bağcılar', search: 'istoc bagcilar ticaret merkezi', lat: 41.071, lng: 28.827, type: 'Bölge' },
  { display: 'Marmara Forum, Bakırköy', search: 'marmara forum bakirkoy osmaniye', lat: 40.997, lng: 28.887, type: 'AVM' },
  { display: 'Akasya AVM, Üsküdar', search: 'akasya avm uskudar acibadem', lat: 41.000, lng: 29.055, type: 'AVM' },
  { display: 'Zorlu Center, Beşiktaş', search: 'zorlu center besiktas zincirlikuyu', lat: 41.067, lng: 29.017, type: 'AVM' },
  { display: 'Vadistanbul, Sarıyer', search: 'vadistanbul sariyer ayazaga', lat: 41.103, lng: 28.989, type: 'AVM' },
  { display: 'Acıbadem Mahallesi, Üsküdar', search: 'acibadem mahallesi uskudar kadikoy', lat: 41.005, lng: 29.049, type: 'Mahalle' },
  { display: 'Atatürk Mahallesi, Ataşehir', search: 'ataturk mahallesi atasehir finans merkezi', lat: 40.993, lng: 29.124, type: 'Mahalle' },
  { display: 'Kayışdağı Mahallesi, Ataşehir', search: 'kayisdagi mahallesi atasehir', lat: 40.970, lng: 29.151, type: 'Mahalle' },
  { display: 'Yeşilköy Mahallesi, Bakırköy', search: 'yesilkoy mahallesi bakirkoy', lat: 40.962, lng: 28.826, type: 'Mahalle' },
  { display: 'Florya Mahallesi, Bakırköy', search: 'florya mahallesi bakirkoy', lat: 40.974, lng: 28.787, type: 'Mahalle' },
  { display: 'Göktürk Merkez Mahallesi, Eyüpsultan', search: 'gokturk merkez mahallesi eyupsultan', lat: 41.181, lng: 28.889, type: 'Mahalle' },
  { display: 'Merkez Mahallesi, Kağıthane', search: 'merkez mahallesi kagithane', lat: 41.079, lng: 28.973, type: 'Mahalle' },
  { display: 'Seyrantepe Mahallesi, Kağıthane', search: 'seyrantepe mahallesi kagithane', lat: 41.090, lng: 28.999, type: 'Mahalle' },
  { display: 'Cevizli Mahallesi, Kartal', search: 'cevizli mahallesi kartal', lat: 40.912, lng: 29.176, type: 'Mahalle' },
  { display: 'Aydınevler Mahallesi, Maltepe', search: 'aydinevler mahallesi maltepe', lat: 40.953, lng: 29.131, type: 'Mahalle' },
  { display: 'Kurtköy Mahallesi, Pendik', search: 'kurtkoy mahallesi pendik sabiha gokcen', lat: 40.914, lng: 29.299, type: 'Mahalle' },
  { display: 'Orhanlı Mahallesi, Tuzla', search: 'orhanli mahallesi tuzla', lat: 40.891, lng: 29.377, type: 'Mahalle' },
  { display: 'Çamlıca Mahallesi, Üsküdar', search: 'camlica mahallesi uskudar', lat: 41.027, lng: 29.068, type: 'Mahalle' },
  { display: 'Bostancı Mahallesi, Kadıköy', search: 'bostanci mahallesi kadikoy', lat: 40.958, lng: 29.094, type: 'Mahalle' },
  { display: 'Cihangir Mahallesi, Beyoğlu', search: 'cihangir mahallesi beyoglu', lat: 41.031, lng: 28.984, type: 'Mahalle' },
];

let servicePricing = {
  normal: { base: 240, km: 14, multiplier: 1, label: 'Motorlu Kurye' },
  express: { base: 320, km: 17, multiplier: 1.25, label: 'Express Kurye' },
  vip: { base: 420, km: 20, multiplier: 1.55, label: 'VIP Kurye' },
  aracli: { base: 650, km: 28, multiplier: 1.75, label: 'Arabalı Kurye' },
  eticaret: { base: 260, km: 13, multiplier: 0.95, label: 'E-Ticaret Teslimatı' },
};

let packageFees = {
  evrak: 0,
  zarf: 0,
  kucuk: 60,
  orta: 120,
  buyuk: 220,
  hacimli: 240,
  motorDisi: 430,
};

let pricingRules = {
  routeMultiplier: 1.28,
  minSameAreaKm: 4,
  minDefaultKm: 7,
  bridgeFee: 90,
  roundTo: 10,
  homeMinFactor: 0.92,
  homeMaxFactor: 1.08,
};

const normalizeText = (value) => value
  .toLocaleLowerCase('tr-TR')
  .normalize('NFD')
  .replace(/[\u0300-\u036f]/g, '');

const selectedLocationForInput = (input) => {
  if (!input?.dataset.selectedLabel || input.value.trim() !== input.dataset.selectedLabel) return null;
  return {
    display: input.dataset.selectedLabel,
    lat: Number(input.dataset.selectedLat),
    lng: Number(input.dataset.selectedLng),
    type: input.dataset.selectedType || 'Adres',
  };
};

const calculateDistance = (from, to) => {
  const earthRadius = 6371;
  const latDelta = (to.lat - from.lat) * Math.PI / 180;
  const lngDelta = (to.lng - from.lng) * Math.PI / 180;
  const a = Math.sin(latDelta / 2) ** 2
    + Math.cos(from.lat * Math.PI / 180) * Math.cos(to.lat * Math.PI / 180)
    * Math.sin(lngDelta / 2) ** 2;
  return earthRadius * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
};

const formatPrice = (value) => {
  const roundTo = Number(pricingRules.roundTo) > 0 ? Number(pricingRules.roundTo) : 10;
  return `${Math.round(value / roundTo) * roundTo} TL`;
};

const loadPricingConfig = async () => {
  try {
    const response = await fetch('api/pricing-config.php', { headers: { Accept: 'application/json' } });
    if (!response.ok) return;
    const data = await response.json();
    if (!data.ok || !data.pricing) return;

    if (data.pricing.services) servicePricing = data.pricing.services;
    if (data.pricing.packages) {
      packageFees = Object.fromEntries(
        Object.entries(data.pricing.packages).map(([key, value]) => [key, Number(value.fee) || 0])
      );
    }
    if (data.pricing.rules) pricingRules = { ...pricingRules, ...data.pricing.rules };

    updatePriceEstimate();
    updateDetailEstimate();
  } catch {
    // Fallback constants keep the form usable if the pricing endpoint is unavailable.
  }
};


const isValidTckn = (value) => {
  if (!value) return true;
  if (!/^[1-9][0-9]{10}$/.test(value)) return false;

  const digits = value.split('').map(Number);
  const oddSum = digits[0] + digits[2] + digits[4] + digits[6] + digits[8];
  const evenSum = digits[1] + digits[3] + digits[5] + digits[7];
  const digit10 = ((oddSum * 7) - evenSum) % 10;
  const digit11 = digits.slice(0, 10).reduce((sum, digit) => sum + digit, 0) % 10;

  return digit10 === digits[9] && digit11 === digits[10];
};

const setFieldError = (input, message = '') => {
  const existing = input.parentElement?.querySelector('.field-error');
  existing?.remove();
  input.setCustomValidity(message);

  if (message) {
    const error = document.createElement('small');
    error.className = 'field-error';
    error.textContent = message;
    input.insertAdjacentElement('afterend', error);
  }
};

const updateScheduleFields = () => {
  if (!detailForm || !scheduleFields) return;

  const value = detailForm.elements.deliveryTime?.value || '';
  const requiresTime = value === 'Belirli saat aralığı';
  const requiresDate = value === 'İleri tarihli teslimat';
  const hasScheduleChoice = requiresTime || requiresDate;
  const dateInput = detailForm.elements.deliveryDate;
  const startInput = detailForm.elements.deliveryStartTime;
  const endInput = detailForm.elements.deliveryEndTime;

  scheduleFields.hidden = !hasScheduleChoice;
  if (scheduleDateField) scheduleDateField.hidden = !requiresDate;
  scheduleTimeFields.forEach((field) => {
    field.hidden = !requiresTime;
  });

  if (dateInput) {
    dateInput.required = requiresDate;
    dateInput.disabled = !requiresDate;
    dateInput.min = new Date().toISOString().slice(0, 10);
    if (!requiresDate) dateInput.value = '';
  }
  [startInput, endInput].forEach((input) => {
    if (!input) return;
    input.required = requiresTime;
    input.disabled = !requiresTime;
    if (!requiresTime) input.value = '';
  });
};

const updatePriceEstimate = () => {
  if (!deliveryForm || !priceEstimate) return;

  const pickup = selectedLocationForInput(deliveryForm.elements.pickup);
  const dropoff = selectedLocationForInput(deliveryForm.elements.dropoff);
  const service = servicePricing[deliveryForm.elements.service?.value] || servicePricing.normal;
  const packageFee = packageFees[deliveryForm.elements.packageType?.value] || 0;

  if (!pickup || !dropoff) {
    priceEstimate.querySelector('strong').textContent = 'Adres seçince hesaplanır';
    return;
  }

  const rawDistance = calculateDistance(pickup, dropoff);
  const billableDistance = Math.max(
    rawDistance * Number(pricingRules.routeMultiplier),
    pickup.display === dropoff.display ? Number(pricingRules.minSameAreaKm) : Number(pricingRules.minDefaultKm)
  );
  const bridgeFee = pickup.lng < 29 && dropoff.lng >= 29 || pickup.lng >= 29 && dropoff.lng < 29 ? Number(pricingRules.bridgeFee) : 0;
  const estimate = (service.base + billableDistance * service.km + packageFee + bridgeFee) * service.multiplier;
  const min = estimate * Number(pricingRules.homeMinFactor);
  const max = estimate * Number(pricingRules.homeMaxFactor);

  priceEstimate.querySelector('strong').textContent = `${formatPrice(min)} - ${formatPrice(max)}`;
};

const calculateEstimate = (pickup, dropoff, serviceValue = 'normal', packageValue = 'evrak') => {
  const service = servicePricing[serviceValue] || servicePricing.normal;
  const packageFee = packageFees[packageValue] || 0;
  const rawDistance = calculateDistance(pickup, dropoff);
  const billableDistance = Math.max(
    rawDistance * Number(pricingRules.routeMultiplier),
    pickup.display === dropoff.display ? Number(pricingRules.minSameAreaKm) : Number(pricingRules.minDefaultKm)
  );
  const bridgeFee = pickup.lng < 29 && dropoff.lng >= 29 || pickup.lng >= 29 && dropoff.lng < 29 ? Number(pricingRules.bridgeFee) : 0;
  const estimate = (service.base + billableDistance * service.km + packageFee + bridgeFee) * service.multiplier;
  return formatPrice(estimate);
};

const calculateBillableDistance = (pickup, dropoff) => {
  const rawDistance = calculateDistance(pickup, dropoff);
  return Math.max(
    rawDistance * Number(pricingRules.routeMultiplier),
    pickup.display === dropoff.display ? Number(pricingRules.minSameAreaKm) : Number(pricingRules.minDefaultKm)
  );
};

const updateDetailEstimate = () => {
  if (!detailForm || !detailPriceEstimate) return;

  const pickup = selectedLocationForInput(detailForm.elements.pickup);
  const dropoff = selectedLocationForInput(detailForm.elements.dropoff);
  const target = detailPriceEstimate.querySelector('strong');

  if (!pickup || !dropoff) {
    target.textContent = 'Mahalleleri seçince hesaplanır';
    if (requestSummary?.querySelector('[data-summary-price]')) {
      requestSummary.querySelector('[data-summary-price]').textContent = 'Mahalleleri seçince hesaplanır';
    }
    return;
  }

  const serviceValue = detailForm.elements.service.value;
  const packageValue = detailForm.elements.packageType.value;
  const estimate = calculateEstimate(pickup, dropoff, serviceValue, packageValue);
  target.textContent = estimate;
  if (requestSummary?.querySelector('[data-summary-price]')) {
    requestSummary.querySelector('[data-summary-price]').textContent = estimate;
  }
};

const closeAutocomplete = (input) => {
  const list = input.closest('.autocomplete-field')?.querySelector('[data-autocomplete-list]');
  list?.classList.remove('is-open');
  if (list) list.innerHTML = '';
};

const selectLocation = (input, location) => {
  input.value = location.display;
  input.dataset.selectedLabel = location.display;
  input.dataset.selectedLat = String(location.lat);
  input.dataset.selectedLng = String(location.lng);
  input.dataset.selectedType = location.type;
  addressInputs.forEach(closeAutocomplete);
  window.setTimeout(() => input.blur(), 0);
  updatePriceEstimate();
  updateDetailEstimate();
};

const fetchRemoteLocations = async (query) => {
  const neighborhoodOnly = document.activeElement?.dataset.addressScope === 'neighborhood';
  const url = new URL('https://nominatim.openstreetmap.org/search');
  url.searchParams.set('format', 'jsonv2');
  url.searchParams.set('addressdetails', '1');
  url.searchParams.set('limit', '12');
  url.searchParams.set('countrycodes', 'tr');
  url.searchParams.set('accept-language', 'tr');
  url.searchParams.set('viewbox', '28.01,41.65,29.95,40.72');
  url.searchParams.set('bounded', '1');
  url.searchParams.set('q', `${query}${neighborhoodOnly ? ' Mahallesi' : ''}, İstanbul`);

  try {
    const response = await fetch(url.toString(), { headers: { Accept: 'application/json' } });
    if (!response.ok) return [];
    const results = await response.json();
    return results
      .filter((result) => {
        const address = result.address || {};
        const haystack = normalizeText([
          result.display_name,
          address.city,
          address.province,
          address.state,
          address.county,
        ].filter(Boolean).join(' '));
        const isIstanbul = haystack.includes('istanbul');
        const isNeighborhood = address.neighbourhood || address.suburb || address.quarter || result.type === 'neighbourhood';
        return isIstanbul && (!neighborhoodOnly || isNeighborhood);
      })
      .map((result) => ({
        display: formatRemoteAddress(result),
        search: result.display_name,
        lat: Number(result.lat),
        lng: Number(result.lon),
        type: getRemoteAddressType(result),
      }))
      .filter((result) => Number.isFinite(result.lat) && Number.isFinite(result.lng));
  } catch {
    return [];
  }
};

const getRemoteAddressType = (result) => {
  const address = result.address || {};
  if (address.road || result.type === 'road') return 'Cadde/Sokak';
  if (address.neighbourhood || address.suburb || address.quarter) return 'Mahalle/Semt';
  if (address.mall || result.class === 'shop') return 'AVM/İşletme';
  return 'Adres';
};

const formatRemoteAddress = (result) => {
  const address = result.address || {};
  const parts = [
    address.road || address.pedestrian || address.footway || address.cycleway || address.path,
    address.house_number,
    address.neighbourhood || address.suburb || address.quarter,
    address.town || address.county || address.city_district,
  ].filter(Boolean);

  if (parts.length) return [...new Set(parts)].join(', ');

  return result.display_name
    .split(',')
    .slice(0, 4)
    .map((part) => part.trim())
    .join(', ');
};

const renderOptions = (input, locations) => {
  const field = input.closest('.autocomplete-field');
  const list = field?.querySelector('[data-autocomplete-list]');
  if (!list) return;

  list.innerHTML = '';

  if (!locations.length) {
    list.classList.remove('is-open');
    return;
  }

  locations.slice(0, 8).forEach((location) => {
    const option = document.createElement('button');
    option.type = 'button';
    option.className = 'autocomplete-option';
    option.innerHTML = `<strong>${location.display}</strong><small>${location.type}</small>`;
    option.addEventListener('pointerdown', (event) => {
      event.preventDefault();
    });
    option.addEventListener('click', () => {
      selectLocation(input, location);
    });
    list.append(option);
  });

  list.classList.add('is-open');
};

const renderAutocompleteStatus = (input, message) => {
  const field = input.closest('.autocomplete-field');
  const list = field?.querySelector('[data-autocomplete-list]');
  if (!list) return;

  list.innerHTML = '';
  const status = document.createElement('div');
  status.className = 'autocomplete-status';
  status.textContent = message;
  list.append(status);
  list.classList.add('is-open');
};

const renderAutocomplete = async (input) => {
  const rawQuery = input.value.trim();
  const query = normalizeText(rawQuery);

  if (input.dataset.selectedLabel && rawQuery === input.dataset.selectedLabel) {
    updatePriceEstimate();
    return;
  }

  input.removeAttribute('data-selected-label');
  input.removeAttribute('data-selected-lat');
  input.removeAttribute('data-selected-lng');
  input.removeAttribute('data-selected-type');
  updatePriceEstimate();

  if (query.length < 2) {
    closeAutocomplete(input);
    return;
  }

  const localMatches = addressLocations
    .filter((location) => normalizeText(`${location.display} ${location.search}`).includes(query))
    .filter((location) => input.dataset.addressScope !== 'neighborhood' || ['Mahalle', 'Semt', 'İlçe'].includes(location.type))
    .slice(0, 8);

  if (localMatches.length) {
    renderOptions(input, localMatches);
  } else {
    renderAutocompleteStatus(input, 'İstanbul adresleri aranıyor...');
  }

  window.clearTimeout(addressSearchTimers.get(input));
  addressSearchTimers.set(input, window.setTimeout(async () => {
    const remoteMatches = await fetchRemoteLocations(rawQuery);
    const combined = [...localMatches];

    remoteMatches.forEach((remote) => {
      if (!combined.some((item) => normalizeText(item.display) === normalizeText(remote.display))) {
        combined.push(remote);
      }
    });

    if (document.activeElement === input && input.value.trim() === rawQuery) {
      if (combined.length) {
        renderOptions(input, combined);
      } else {
        renderAutocompleteStatus(input, 'Sonuç bulunamadı. Mahalle, cadde veya sokak adı yazın.');
      }
    }
  }, 280));
};

menuToggle?.addEventListener('click', () => {
  const isOpen = header.classList.toggle('is-open');
  menuToggle.setAttribute('aria-expanded', String(isOpen));
});

document.querySelectorAll('.main-nav a, .header-actions a').forEach((link) => {
  link.addEventListener('click', () => {
    header.classList.remove('is-open');
    menuToggle?.setAttribute('aria-expanded', 'false');
  });
});

deliveryForm?.addEventListener('submit', (event) => {
  event.preventDefault();
  const button = deliveryForm.querySelector('button[type="submit"]');
  const pickup = deliveryForm.elements.pickup.value.trim();
  const dropoff = deliveryForm.elements.dropoff.value.trim();

  if (!pickup || !dropoff || !selectedLocationForInput(deliveryForm.elements.pickup) || !selectedLocationForInput(deliveryForm.elements.dropoff)) {
    button.textContent = 'Listeden adres seçin';
    window.setTimeout(() => {
      button.textContent = 'Talep Oluştur';
    }, 1600);
    return;
  }

  updatePriceEstimate();

  const params = new URLSearchParams({
    pickup,
    dropoff,
    pickupLat: deliveryForm.elements.pickup.dataset.selectedLat,
    pickupLng: deliveryForm.elements.pickup.dataset.selectedLng,
    dropoffLat: deliveryForm.elements.dropoff.dataset.selectedLat,
    dropoffLng: deliveryForm.elements.dropoff.dataset.selectedLng,
    price: priceEstimate?.querySelector('strong')?.textContent || 'Hesaplanamadı',
  });

  window.location.href = `talep.html?${params.toString()}`;
});

addressInputs.forEach((input) => {
  input.addEventListener('input', () => {
    renderAutocomplete(input);
  });
  input.addEventListener('focus', () => renderAutocomplete(input));
});

deliveryForm?.querySelectorAll('select').forEach((select) => {
  select.addEventListener('change', updatePriceEstimate);
});

document.addEventListener('click', (event) => {
  addressInputs.forEach((input) => {
    if (!input.closest('.autocomplete-field')?.contains(event.target)) {
      closeAutocomplete(input);
    }
  });
});

if (requestSummary) {
  const params = new URLSearchParams(window.location.search);
  const fields = {
    pickup: params.get('pickup') || 'Belirtilmedi',
    dropoff: params.get('dropoff') || 'Belirtilmedi',
    service: params.get('service') || 'Belirtilmedi',
    packageType: params.get('packageType') || 'Belirtilmedi',
    price: params.get('price') || 'Hesaplanamadı',
  };

  Object.entries(fields).forEach(([key, value]) => {
    const attribute = key.replace(/[A-Z]/g, (letter) => `-${letter.toLowerCase()}`);
    const target = requestSummary.querySelector(`[data-summary-${attribute}]`);
    if (target) target.textContent = value;
  });
}

const fillDetailFormFromParams = () => {
  if (!detailForm) return;

  const params = new URLSearchParams(window.location.search);
  ['pickup', 'dropoff', 'pickupStreet', 'dropoffStreet'].forEach((name) => {
    const input = detailForm.elements[name];
    const value = params.get(name);
    if (input && value) input.value = value;
  });

  [
    ['pickup', 'pickupLat', 'pickupLng'],
    ['dropoff', 'dropoffLat', 'dropoffLng'],
  ].forEach(([name, latKey, lngKey]) => {
    const input = detailForm.elements[name];
    const lat = params.get(latKey);
    const lng = params.get(lngKey);
    if (input?.value && lat && lng) {
      input.dataset.selectedLabel = input.value;
      input.dataset.selectedLat = lat;
      input.dataset.selectedLng = lng;
      input.dataset.selectedType = 'Mahalle/Semt';
    }
  });
};

detailForm?.addEventListener('submit', (event) => {
  event.preventDefault();
  const button = detailForm.querySelector('button[type="submit"]');
  const pickup = detailForm.elements.pickup;
  const dropoff = detailForm.elements.dropoff;
  const invalidTckn = [...detailForm.querySelectorAll('[data-tckn]')]
    .find((input) => input.hasAttribute('required')
      ? !isValidTckn(input.value.trim())
      : input.value.trim() !== '' && !isValidTckn(input.value.trim()));

  if (!selectedLocationForInput(pickup) || !selectedLocationForInput(dropoff)) {
    button.textContent = 'Mahalleleri listeden seçin';
    window.setTimeout(() => {
      button.textContent = 'Talebi Tamamla';
    }, 1600);
    return;
  }

  if (invalidTckn) {
    setFieldError(invalidTckn, 'Geçerli bir T.C. kimlik numarası girin.');
    invalidTckn.reportValidity();
    return;
  }

  const deliveryTime = detailForm.elements.deliveryTime?.value || '';
  const startTime = detailForm.elements.deliveryStartTime;
  const endTime = detailForm.elements.deliveryEndTime;
  if (deliveryTime === 'Belirli saat aralığı' && startTime?.value && endTime?.value && startTime.value >= endTime.value) {
    setFieldError(endTime, 'Bitiş saati başlangıçtan sonra olmalı.');
    endTime.reportValidity();
    return;
  }
  if (endTime) setFieldError(endTime, '');

  if (!detailForm.reportValidity()) return;

  const formData = new FormData(detailForm);
  const selectedService = detailForm.querySelector('input[name="service"]:checked');
  const selectedPackage = detailForm.querySelector('input[name="packageType"]:checked');
  const payload = Object.fromEntries(formData.entries());

  payload.pickupLat = pickup.dataset.selectedLat;
  payload.pickupLng = pickup.dataset.selectedLng;
  payload.dropoffLat = dropoff.dataset.selectedLat;
  payload.dropoffLng = dropoff.dataset.selectedLng;
  payload.price = detailPriceEstimate?.querySelector('[data-summary-price]')?.textContent || 'Hesaplanamadı';
  payload.distanceKm = calculateBillableDistance(selectedLocationForInput(pickup), selectedLocationForInput(dropoff)).toFixed(2);
  payload.serviceLabel = selectedService?.nextElementSibling?.textContent?.trim() || payload.service;
  payload.packageLabel = selectedPackage?.nextElementSibling?.textContent?.trim() || payload.packageType;
  payload.serviceAgreement = detailForm.elements.serviceAgreement.checked;
  payload.kvkkConsent = detailForm.elements.kvkkConsent.checked;

  const defaultButtonText = button.textContent;
  button.disabled = true;
  button.textContent = 'Talep gönderiliyor...';

  fetch('api/talep-olustur.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify(payload),
  })
    .then(async (response) => {
      const data = await response.json().catch(() => ({}));
      if (!response.ok || !data.ok) {
        const error = new Error(data.message || 'Talep gönderilemedi.');
        error.code = data.code || `HTTP_${response.status}`;
        throw error;
      }
      window.location.href = data.redirect || 'talep-basarili.html';
    })
    .catch((error) => {
      button.disabled = false;
      const code = error.code ? ` (${error.code})` : '';
      button.textContent = `${error.message || defaultButtonText}${code}`;
      window.setTimeout(() => {
        button.textContent = defaultButtonText;
      }, 3600);
    });
});

fillDetailFormFromParams();
updateScheduleFields();
updateDetailEstimate();
if (priceEstimate || detailPriceEstimate) {
  loadPricingConfig();
}

detailForm?.querySelectorAll('input[type="radio"], select').forEach((input) => {
  input.addEventListener('change', () => {
    updateScheduleFields();
    updateDetailEstimate();
  });
});

detailForm?.querySelectorAll('textarea').forEach((textarea) => {
  textarea.addEventListener('input', updateDetailEstimate);
});

detailForm?.querySelectorAll('[data-tckn]').forEach((input) => {
  input.addEventListener('input', () => {
    input.value = input.value.replace(/\D/g, '').slice(0, 11);
    const value = input.value.trim();
    const isRequired = input.hasAttribute('required');
    setFieldError(input, (!isRequired && value === '') || isValidTckn(value) ? '' : 'Geçerli bir T.C. kimlik numarası girin.');
  });
  input.addEventListener('blur', () => {
    const value = input.value.trim();
    const isRequired = input.hasAttribute('required');
    setFieldError(input, (!isRequired && value === '') || isValidTckn(value) ? '' : 'Geçerli bir T.C. kimlik numarası girin.');
  });
});

const trackingForm = document.querySelector('[data-tracking-form]');
const trackingResult = document.querySelector('[data-tracking-result]');
const trackingMessage = document.querySelector('[data-tracking-message]');

const escapeHtml = (value) => String(value ?? '')
  .replace(/&/g, '&amp;')
  .replace(/</g, '&lt;')
  .replace(/>/g, '&gt;')
  .replace(/"/g, '&quot;')
  .replace(/'/g, '&#039;');

const formatTrackingDate = (value) => {
  if (!value) return '-';
  const normalized = String(value).replace(' ', 'T');
  const date = new Date(normalized);
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleString('tr-TR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
};

const showTrackingMessage = (message, type = 'error') => {
  if (!trackingMessage) return;
  trackingMessage.hidden = false;
  trackingMessage.className = `tracking-message tracking-message-${type}`;
  trackingMessage.textContent = message;
};

const hideTrackingMessage = () => {
  if (!trackingMessage) return;
  trackingMessage.hidden = true;
  trackingMessage.textContent = '';
};

const renderTrackingResult = (data) => {
  if (!trackingResult) return;
  const request = data.request;
  const timeline = Array.isArray(data.timeline) ? data.timeline : [];
  const rows = [
    ['Durum', request.status_label],
    ['Alım', request.pickup],
    ['Teslim', request.dropoff],
    ['Hizmet', request.service_label],
    ['Paket', request.package_label],
    ['Teslimat tercihi', request.delivery_time],
    ['Mesafe', request.distance_km ? `${request.distance_km} km` : '-'],
    ['Ücret', request.price || '-'],
    ['Oluşturma', formatTrackingDate(request.created_at)],
  ];

  trackingResult.hidden = false;
  trackingResult.innerHTML = `
    <div class="tracking-card tracking-card-main">
      <div>
        <p class="eyebrow">Güncel durum</p>
        <h2>${escapeHtml(request.status_label)}</h2>
        <p class="tracking-code">${escapeHtml(request.tracking_code)}</p>
      </div>
      <span class="panel-status panel-status-${escapeHtml(request.status)}">${escapeHtml(request.status_label)}</span>
    </div>
    <div class="tracking-grid">
      ${rows.map(([label, value]) => `
        <article>
          <span>${escapeHtml(label)}</span>
          <strong>${escapeHtml(value || '-')}</strong>
        </article>
      `).join('')}
    </div>
    <div class="tracking-card">
      <div class="tracking-card-heading">
        <h2>İşlem Geçmişi</h2>
        <span>${timeline.length} kayıt</span>
      </div>
      <div class="tracking-timeline">
        ${timeline.length ? timeline.map((item) => `
          <article>
            <span class="tracking-dot"></span>
            <div>
              <strong>${escapeHtml(item.label)}</strong>
              ${item.note ? `<p>${escapeHtml(item.note)}</p>` : ''}
              <time>${escapeHtml(formatTrackingDate(item.created_at))}</time>
            </div>
          </article>
        `).join('') : '<p class="tracking-empty">Henüz işlem geçmişi bulunmuyor.</p>'}
      </div>
    </div>
  `;
};

trackingForm?.addEventListener('submit', async (event) => {
  event.preventDefault();
  hideTrackingMessage();
  if (trackingResult) trackingResult.hidden = true;

  const input = trackingForm.elements.trackingCode;
  const trackingCode = input.value.trim().toUpperCase();
  if (!trackingCode) {
    showTrackingMessage('Talep numarasını girin.');
    input.focus();
    return;
  }

  const button = trackingForm.querySelector('button[type="submit"]');
  const defaultText = button.textContent;
  button.disabled = true;
  button.textContent = 'Sorgulanıyor...';

  try {
    const response = await fetch('api/talep-takip.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ trackingCode }),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data.ok) {
      throw new Error(data.message || 'Talep bilgisi alınamadı.');
    }
    renderTrackingResult(data);
    window.history.replaceState(null, '', `takip.html?no=${encodeURIComponent(trackingCode)}`);
  } catch (error) {
    showTrackingMessage(error.message || 'Talep bilgisi alınamadı.');
  } finally {
    button.disabled = false;
    button.textContent = defaultText;
  }
});

if (trackingForm) {
  const codeFromUrl = new URLSearchParams(window.location.search).get('no');
  if (codeFromUrl) {
    trackingForm.elements.trackingCode.value = codeFromUrl;
    trackingForm.requestSubmit();
  }
}
