function addCustomField() {
    const wrapper = document.getElementById('custom-fields-wrapper');
    const template = document.getElementById('custom-field-template').innerHTML;
    wrapper.insertAdjacentHTML('beforeend', template);
}