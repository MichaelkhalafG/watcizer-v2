import PropTypes from 'prop-types';
import List from '@mui/material/List';
import ListItem from '@mui/material/ListItem';
import ListItemButton from '@mui/material/ListItemButton';
import ListItemText from '@mui/material/ListItemText';
import DialogTitle from '@mui/material/DialogTitle';
import Dialog from '@mui/material/Dialog';
import IconButton from '@mui/material/IconButton';
import { FaTimes } from 'react-icons/fa';
import { useContext } from 'react';
import { MyContext } from '../../Context/Context';

function LanguageDialog({ onClose, selectedValue, open }) {
    const { language } = useContext(MyContext);

    const handleClose = () => {
        onClose(selectedValue);
    };

    const handleListItemClick = (value) => {
        onClose(value);
    };

    const languages = [
        { code: 'en', labelen: 'English', labelar: 'الإنجليزية' },
        { code: 'ar', labelen: 'Arabic', labelar: 'العربية' },
    ];

    return (
        <Dialog onClose={handleClose} open={open}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', paddingRight: '16px' }}>
                <DialogTitle>{selectedValue === 'ar' ? 'اختر اللغة' : 'Select Language'}</DialogTitle>
                <IconButton onClick={handleClose} aria-label="close">
                    <FaTimes />
                </IconButton>
            </div>
            <List sx={{ pt: 0 }}>
                {languages.map((lang) => (
                    <ListItem disableGutters key={lang.code}>
                        <ListItemButton onClick={() => handleListItemClick(lang.code)}>
                            <ListItemText primary={language === 'ar' ? lang.labelar : lang.labelen} />
                        </ListItemButton>
                    </ListItem>
                ))}
            </List>
        </Dialog>
    );
}

LanguageDialog.propTypes = {
    onClose: PropTypes.func.isRequired,
    open: PropTypes.bool.isRequired,
    selectedValue: PropTypes.string.isRequired,
};

export default LanguageDialog;
