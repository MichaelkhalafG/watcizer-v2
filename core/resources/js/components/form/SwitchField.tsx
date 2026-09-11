import { Field, type FieldShellProps } from '@/components/form/Field';
import { Switch } from '@/components/ui/switch';

/** Label on the start edge, control on the end edge — mirrored automatically in RTL. */
export function SwitchField({
    checked,
    onChange,
    disabled,
    ...shell
}: FieldShellProps & { checked: boolean; onChange: (checked: boolean) => void; disabled?: boolean }) {
    return (
        <Field
            {...shell}
            inline
            render={(attrs) => <Switch {...attrs} checked={checked} disabled={disabled} onCheckedChange={onChange} />}
        />
    );
}
