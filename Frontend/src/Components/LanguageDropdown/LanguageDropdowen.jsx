import { useState, useContext } from 'react';
import Button from '@mui/material/Button';
import { FaAngleDown } from 'react-icons/fa';
import LanguageDialog from './LanguageDialog';
import { MyContext } from '../../Context/Context';

export default function LanguageDropdown() {
    const [open, setOpen] = useState(false);
    const { language, setLanguage } = useContext(MyContext);

    const handleClickOpen = () => setOpen(true);

    const handleClose = (value) => {
        setOpen(false);
        if (value) {
            setLanguage(value);
            document.documentElement.dir = value === 'ar' ? 'rtl' : 'ltr'; // Change direction based on language
        }
    };

    return (
        <div>
            <Button className='lang-drop border border-2 rounded-3' onClick={handleClickOpen}>
                <div className='dropdown-info text-start d-flex flex-column'>
                    <span className='label text-secondary'>{language === 'ar' ? 'لغتك' : 'Your Language'}</span>
                    <span className='language color-most-used'>{language === 'ar' ? 'العربية' : 'English'}</span>
                </div>
                <span className='me-auto ms-3'><FaAngleDown /></span>
            </Button>
            <LanguageDialog selectedValue={language} open={open} onClose={handleClose} />
        </div>
    );
}
