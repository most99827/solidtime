import{ao as a}from"./app-BWJ_0LHL.js";function e(s){return a.isAxiosError(s)&&s.response?.status===422}function n(s){if(!e(s))return{};const r={};for(const[t,i]of Object.entries(s.response?.data?.errors??{}))Array.isArray(i)&&i[0]&&(r[t]=i[0]);return r}function f(s,r){return e(s)?s.response?.data?.message??r:r}export{f as a,n as g,e as i};
//# sourceMappingURL=apiValidation-DuS3qA2a.js.map
