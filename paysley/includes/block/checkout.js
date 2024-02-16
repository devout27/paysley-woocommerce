// const settings = window.wc.wcSettings.getSetting( 'paysley', {} );
const settings = paysley_settings;
const label = window.wp.htmlEntities.decodeEntities( settings.title ) || window.wp.i18n.__( 'paysley', 'paysley' );
const Content = () => {
    return window.wp.htmlEntities.decodeEntities( settings.description || '' );
};

const labelImage = window.wp.element.createElement('img',{className:'paysley_logo',id:'paysley_logo',src:settings.icon,alt:label});
const titleEle = window.wp.element.createElement('span',{className:'paysley_title_text',id:'paysley_title'},settings.title);

const payementMethodHtml = window.wp.element.createElement('div',{className:'paysley_payment_method_box',id:'paysley_title_with_image',style:{'display':'flex'}},labelImage,titleEle);


const Block_Gateway = {
    name: 'paysley',
    label: payementMethodHtml,
    content: Object( window.wp.element.createElement )( Content, null ),
    edit: Object( window.wp.element.createElement )( Content, null ),
    canMakePayment: () => true,
    ariaLabel: label,
    supports: {
        features: ['products'],
    },
};

window.wc.wcBlocksRegistry.registerPaymentMethod( Block_Gateway );
// console.log('here i am now');
// console.log(settings);
  